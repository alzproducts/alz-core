# COR-297: exits 0 only when the orders profile output has its full shape and every distribution row covers at least 5 orders.
# jq -e -f database/seed-profile/orders-profile-output-check.jq examples/seed-profile/orders-profile.json
def int: type == "number" and . == floor and . >= 0;
def dist($key): .rows | type == "array" and all(.[]; has($key) and (.orders | int) and .orders >= 5);

(keys == ["counts", "distributions", "exported_at", "shares", "sql_sha256", "window"])
and (.sql_sha256 | type == "string" and test("^[0-9a-f]{64}$"))
and (.window | (.start | type == "string") and (.end | type == "string"))
and (.counts | keys == ["deduplicated_references", "lines", "raw_rows"] and all(.[]; int))
and (.distributions
    | keys == ["destination_class", "lines_per_order", "payment_method", "price_tuples", "qty"]
    and all(.[]; .suppressed | int)
    and (.payment_method | dist("payment_method"))
    and (.lines_per_order | dist("line_count"))
    and (.qty | dist("qty") and all(.rows[]; .lines | int))
    and (.price_tuples | dist("price") and all(.rows[]; has("price_vat") and has("vat_rate") and (.lines | int)))
    and (.destination_class | dist("destination_class")))
and (.shares
    | keys == ["cancelled", "edited", "part_refunded", "refunded", "vat_relief", "zero_rated_line"]
    and all(.[]; (.orders | int) and (.share == null or (.share | type == "number"))))
