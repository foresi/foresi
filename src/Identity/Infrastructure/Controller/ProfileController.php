<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Controller;

use Foresi\Identity\Application\ManageProfile;
use Foresi\Identity\Application\OpenToGuest;
use Foresi\Identity\Application\ProfileRefused;
use Foresi\Identity\Application\UpdateProfileInput;
use Foresi\Identity\Application\UserPhotos;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The person's own profile, the first section of their account: the photo, the
 * name, the e-mail and the language. The name and the language are edited in a
 * dialog from the page header (Google, Pipedrive: plain fields, no check), the
 * photo in a dialog of its own that crops it to a square in the browser
 * (GitHub, Google: upload, crop, save), with Remove as a separate action
 * (GitHub, Linear). Every role has one, guests too. The photo is served to
 * anyone signed in, never publicly.
 */
#[OpenToGuest]
final class ProfileController extends AbstractController
{
    public const string ROUTE = 'app_account_profile';
    public const string EDIT_ROUTE = 'app_account_profile_edit';
    public const string PHOTO_EDIT_ROUTE = 'app_account_profile_photo';
    public const string PHOTO_REMOVE_ROUTE = 'app_account_profile_photo_remove';
    public const string PHOTO_ROUTE = 'app_user_photo';
    public const string TOKEN = 'profile';

    public function __construct(
        private readonly ManageProfile $profile,
        private readonly UserRepository $users,
        private readonly UserPhotos $photos,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/account/profile', name: self::ROUTE, methods: ['GET'])]
    public function index(#[CurrentUser] SecurityUser $me): Response
    {
        return $this->render('@Identity/profile/index.html.twig', ['user' => $this->user($me)]);
    }

    /**
     * The name and the language in a dialog; the same URL is a page of its own.
     */
    #[Route('/account/profile/edit', name: self::EDIT_ROUTE, methods: ['GET', 'POST'])]
    public function edit(Request $request, #[CurrentUser] SecurityUser $me): Response
    {
        $user = $this->user($me);
        $name = $user->displayName();
        $language = $user->locale();
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $name = $request->request->getString('displayName');
            $language = $request->request->getString('language');
            try {
                $this->profile->update($user->id(), new UpdateProfileInput($name, $language));
                // The new language is the one the answer speaks.
                $this->addFlash('success', $this->translator->trans('profile.flash.saved', locale: $language));

                return $this->redirectToRoute(self::ROUTE);
            } catch (ProfileRefused $refused) {
                $errors[(string) $refused->field()] = $this->translator->trans('profile.error.'.$refused->reason());
            }
        }

        return $this->render('@Identity/profile/edit.html.twig', [
            'name' => $name,
            'language' => $language,
            'languages' => array_combine(User::LOCALES, array_map(fn (string $locale): string => $this->translator->trans('locale.'.$locale), User::LOCALES)),
            'errors' => $errors,
        ], new Response(status: [] === $errors ? 200 : 422));
    }

    /**
     * Upload a photo in a dialog. With JavaScript the browser sends the square the person cropped;
     * without it the server takes the middle square. Either way the server draws it anew.
     */
    #[Route('/account/profile/photo', name: self::PHOTO_EDIT_ROUTE, methods: ['GET', 'POST'])]
    public function photo(Request $request, #[CurrentUser] SecurityUser $me): Response
    {
        $user = $this->user($me);
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $file = $request->files->get('photo');
            try {
                $this->profile->changePhoto($user->id(), $this->uploaded($file));
                $this->addFlash('success', $this->translator->trans('profile.flash.photo_saved'));

                return $this->redirectToRoute(self::ROUTE);
            } catch (ProfileRefused $refused) {
                $error = $this->translator->trans('profile.error.'.$refused->reason());
            }
        }

        return $this->render('@Identity/profile/photo.html.twig', ['user' => $user, 'error' => $error], new Response(status: null === $error ? 200 : 422));
    }

    #[Route('/account/profile/photo/remove', name: self::PHOTO_REMOVE_ROUTE, methods: ['POST'])]
    public function removePhoto(Request $request, #[CurrentUser] SecurityUser $me): RedirectResponse
    {
        $this->checkToken($request);
        $this->profile->removePhoto($me->id());
        $this->addFlash('success', $this->translator->trans('profile.flash.photo_removed'));

        return $this->redirectToRoute(self::ROUTE);
    }

    /**
     * A person's photo, for anyone signed in: the address carries its version, so a browser keeps it
     * until the person changes it.
     */
    #[Route('/people/{id}/photo', name: self::PHOTO_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id, Request $request): BinaryFileResponse
    {
        $user = $this->users->findById($id);
        $path = null === $user?->photoVersion() ? null : $this->photos->path($id);
        if (null === $path) {
            throw new NotFoundHttpException('The person has no photo.');
        }
        $response = new BinaryFileResponse($path, headers: ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff']);
        $response->setPrivate();
        $response->setMaxAge($request->query->getString('v') === $user->photoVersion() ? 31_536_000 : 0);

        return $response;
    }

    private function uploaded(mixed $file): string
    {
        if (!$file instanceof UploadedFile) {
            throw ProfileRefused::photo(ProfileRefused::PHOTO_TYPE, 'Choose a photo.');
        }
        if (!$file->isValid()) {
            // A server limit refuses the file before Foresi sees it; the person only needs to know it is too large.
            $reason = \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) ? ProfileRefused::PHOTO_TOO_LARGE : ProfileRefused::PHOTO_TYPE;
            throw ProfileRefused::photo($reason, 'The photo did not arrive.');
        }
        if ($file->getSize() > UserPhotos::MAX_BYTES) {
            throw ProfileRefused::photo(ProfileRefused::PHOTO_TOO_LARGE, 'A photo has at most 5 MB.');
        }

        return (string) file_get_contents($file->getPathname());
    }

    private function user(SecurityUser $me): User
    {
        return $this->users->findById($me->id()) ?? throw new NotFoundHttpException('The user does not exist.');
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
