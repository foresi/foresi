<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Controller;

use Foresi\Identity\Contract\StaffCheck;
use Foresi\Invoicing\Application\CustomerOption;
use Foresi\Invoicing\Application\CustomerPicker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The contacts the firm can invoice, for the combobox of the invoice form: found by
 * text, or the one with an id, which the form asks for after the contact was created
 * in another tab.
 */
final class CustomerPickerController extends AbstractController
{
    private const int MAX_QUERY = 100;

    public function __construct(
        private readonly CustomerPicker $picker,
        private readonly StaffCheck $staff,
    ) {
    }

    #[Route('/invoices/customers', name: 'app_invoice_customers', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only staff work with invoices.');
        }

        $contact = $request->query->getString('id');
        $options = '' === $contact
            ? $this->picker->search(mb_substr($request->query->getString('q'), 0, self::MAX_QUERY))
            : $this->one($contact);

        return $this->json(['results' => array_map(static fn (CustomerOption $option): array => [
            'id' => $option->id,
            'label' => $option->name,
            'hint' => $option->registrationNumber ?? '',
            'name' => $option->name,
            'registrationNumber' => $option->registrationNumber,
            'vatId' => $option->vatId,
            'billing' => [
                'street' => $option->billing->street,
                'city' => $option->billing->city,
                'postalCode' => $option->billing->postalCode,
                'countryCode' => $option->billing->countryCode,
            ],
            'email' => $option->email,
            'dueDays' => $option->defaults->dueDays,
            'paymentMethod' => $option->defaults->paymentMethod->value,
            'language' => $option->defaults->language->value,
            'reverseCharge' => $option->defaults->reverseCharge,
            'currency' => $option->defaults->currency->value,
        ], $options)]);
    }

    /**
     * @return list<CustomerOption>
     */
    private function one(string $id): array
    {
        $option = Uuid::isValid($id) ? $this->picker->find($id) : null;

        return $option instanceof CustomerOption ? [$option] : [];
    }
}
