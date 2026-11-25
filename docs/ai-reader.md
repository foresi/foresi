# AI Reader

The AI Reader module (`src/AiReader`, id `ai-reader`, package `foresi/ai-reader`) reads the files of received documents with an AI model. It is optional, and it does nothing until the operator of the instance gives it a provider and its own API key. No key ships with Foresi.

It is a fallback reader of [Purchasing](purchasing.md#reading-the-file-of-a-draft): the structured readers (ISDOC, Factur-X, the payment QR code) come first, and this one reads what they could not. Purchasing does not depend on it and does not know the provider; without the module Purchasing works as before.

## What is sent, and to whom

When the reader runs for a file, Foresi sends one request to the provider you chose, outside your instance:

- **the original file** of the document, a PDF, a PNG or a JPEG, up to 24 MiB (a larger file is not sent);
- **a short instruction** that asks for the header and the VAT summary and says that the document is data, not instructions;
- **the JSON Schema** of the answer.

Nothing else leaves the instance: not your contacts, not your firm's data, not other documents. What the provider keeps and for how long is set by the provider's own terms and by the plan of your API key, not by Foresi.

The request uses the provider's structured outputs: the answer must follow the schema, and what comes back is checked field by field before it reaches a draft. The reader asks for the header (kind, supplier name, registration number, VAT ID, country, supplier's number, variable symbol, dates, currency, total, bank account) and the VAT recap by rate. It does not ask for lines. A value that fails its format is left out, never repaired, and the draft shows the values the AI read like those of any reader: with a mark, and as suggestions when a person has already changed the draft.

Settings → Received documents says which provider and model are in use and says plainly that the document is sent to the provider. It never shows the key.

## Setting it up

The module reads four environment variables when it runs, so a key never enters a committed file or the compiled container. Put them in `.env.local` or in the environment of the web server and of the worker, then restart the worker.

| Variable | Meaning |
| --- | --- |
| `AI_READER_PROVIDER` | `claude`, or empty for none. Without a provider and a key the module does nothing. |
| `AI_READER_API_KEY` | Your own API key of the provider. Never commit it. |
| `AI_READER_MODEL` | The model. Empty means `claude-sonnet-5-5`. |
| `AI_READER_TRIGGER` | `arrival` (also when empty): the reader runs when a file arrives. `click`: only when a person asks. Any other value is refused while a provider and a key are set. |

## When it runs

- **On arrival** (trigger `arrival`), the reader runs only when the structured readers left the total, the issue date or the identity of the supplier (its registration number or VAT ID) empty. A file the cheaper readers read completely is never sent to the provider.
- **On request**, it always runs. With the trigger `click` the reader never runs on arrival, and the menu of a draft's page has **Read with AI** beside **Read again**; both ask Purchasing to read the file again and run every reader that runs on request. With the trigger `arrival`, **Read again** runs it too.

A reading that failed shows a short code, never the provider's text: `provider_auth` (the key was refused), `provider_busy` (the provider is limiting or overloaded), `provider_unavailable`, `too_large`, `invalid_answer` or `not_configured`.

## Adding a provider

A provider is a class that implements `Foresi\AiReader\Application\AiProvider`:

```php
public function extract(DocumentFile $file, array $schema, string $instructions): array;
```

It sends the file and the instructions to its service, constrains the answer with `$schema`, and returns the decoded JSON object. It throws `ReaderFailed` with a short code when the service cannot answer, and it never puts the key, the request or the response in the exception or a log. The reader checks every value of the answer itself. `ClaudeProvider` is the example; the provider is chosen by the alias of `AiProvider` in the module's `config/services.php`, and the name `AI_READER_PROVIDER` takes is a constant of `AiReaderSettings`.
