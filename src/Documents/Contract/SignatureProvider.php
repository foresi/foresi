<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Contract;

/**
 * An electronic signature service that sends a ready document to its signers
 * and returns the signed file with its certificate.
 *
 * Designed, not built: nothing implements this yet, and signing is manual (a
 * person marks signers signed and uploads the signed copy). It is declared so
 * the document keeps the fields a provider needs (provider, envelope id,
 * certificate attachment) from the start.
 */
interface SignatureProvider
{
    /** The provider's code, kept on a document it sent, such as "docusign". */
    public function code(): string;

    /** @return string the provider's envelope id */
    public function send(DocumentForSigning $document): string;

    public function fetchFinal(string $envelopeId): SignedFiles;
}
