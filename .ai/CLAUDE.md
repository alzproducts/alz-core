# .ai Directory Structure

| Directory              | Question it Answers          | Accuracy Model    |
|------------------------|------------------------------|-------------------|
| `docs/`                | "How does this work?"        | Must stay true    |
| `plans/`               | "What were we trying to do?" | Intent snapshot   |
| `implementation-logs/` | "What actually happened?"    | Historical fact   |
| `reports/`             | "What did we learn/find?"    | Analysis snapshot |
| `handoffs/`            | "Where did we stop?"         | Disposable        |

## Lifecycle Flow

```
plans/  →  implementation-logs/  →  reports/  →  docs/
intent      action                  analysis      knowledge
```

This mirrors how work flows: plan it, do it, analyze it, document learnings.
