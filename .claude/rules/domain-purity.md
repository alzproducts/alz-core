---
paths:
  - "app/Domain/**/*.php"
---

# Domain — Purity

- DO NOT read external state in Domain methods: filesystem (`file_*`, `fopen`, `glob`), network (`curl_*`, `fsockopen`), database access, randomness (`mt_rand`, `random_int`, `uniqid`), environment (`getenv`, `$_ENV`), or current time (`time()`, `microtime()`, `new DateTime()` without an argument, `Carbon::now()`). Pass deterministic values in as constructor or method arguments instead.
