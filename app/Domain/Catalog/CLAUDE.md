# Catalog Domain

## VO Construction

Assemblers orchestrate (include checks, relation guards) — they don't construct VOs field-by-field. Delegate construction to the source model (`$model->toProductStock()`), a dedicated mapper, or a self-constructing VO.
