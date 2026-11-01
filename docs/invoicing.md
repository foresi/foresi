# Invoices

## Bulk download and time limits

The download for the accountant draws one PDF after another, so its length grows with the period. It needs no limit raised for that: PHP's `max_execution_time` is reset to 30 seconds for each document and to 300 seconds for closing the archive, so a year of invoices finishes while one stuck document still ends the request.

A web server or proxy in front of PHP counts on its own and knows nothing of this. Set the timeouts there (`request_terminate_timeout` of PHP-FPM, `proxy_read_timeout` or `fastcgi_read_timeout` of nginx) to the longest period you expect, at about a tenth of a second per document, or use a shorter period. A download cut there leaves no half file: the browser reports a failure.

A document that cannot be drawn stops the archive, and the form names it: the accountant never gets a period with a document silently missing.
