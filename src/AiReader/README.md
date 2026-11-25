# foresi/ai-reader

The AI Reader module of [Foresi](https://foresi.app): an optional reader that fills the header and the VAT recap of a received document with an AI model, for the files the structured readers could not read. It does nothing until the operator sets `AI_READER_PROVIDER` and their own `AI_READER_API_KEY`; no key ships. The document is sent to the provider you choose.

It requires Purchasing and Ui, and Purchasing does not know it. What is sent, the four environment variables, when it runs and how to add a provider are in [docs/ai-reader.md](https://github.com/foresi/foresi/blob/main/docs/ai-reader.md).

Issues and pull requests go to [foresi/foresi](https://github.com/foresi/foresi).
