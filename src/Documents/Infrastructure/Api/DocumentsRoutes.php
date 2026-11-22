<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Api;

/**
 * The names of the REST read routes of Documents, for links to them.
 */
final class DocumentsRoutes
{
    public const string DOCUMENTS = 'documents_api_documents';
    public const string DOCUMENT = 'documents_api_document';
    public const string DOCUMENT_FILE = 'documents_api_document_file';
    public const string TYPES = 'documents_api_types';
    public const string TEMPLATES = 'documents_api_templates';
    public const string TEMPLATE = 'documents_api_template';
}
