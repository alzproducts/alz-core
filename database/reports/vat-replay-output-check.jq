# COR-295: gate on the VAT replay output before anyone reads it (exit 0 only for the expected shape).
# Usage: jq -e -f database/reports/vat-replay-output-check.jq examples/vat-replay/vat-replay.json
def count: type == "number" and . >= 0 and . == floor;
def exactly($want): type == "object" and (keys == ($want | sort));
def counts_by($pattern): type == "object" and all(to_entries[]; (.key | test($pattern)) and (.value | count));
def shape($zap):
  "^lines=(1|2|3-5|6\\+);qty=[tf];rates=[0-9]+(,[0-9]+)*;ship=(t|f|unknown);zero=[tf];calc=[a-z_]+;base=[tf]"
  + (if $zap then ";fb=[tf]" else "" end) + "$";
def mode($zap):
  exactly(["orders", "mismatched", "delta_pence", "shapes"] + (if $zap then ["fallback_lines", "fallback_orders"] else [] end))
  and (.orders | count)
  and (.mismatched | count)
  and (.delta_pence | counts_by("^-?[0-9]+$"))
  and (.shapes | counts_by(shape($zap)))
  and (if $zap then (.fallback_lines | count) and (.fallback_orders | count) else true end);
def variant($zap): exactly(["per_line", "per_rate"]) and (.per_line | mode($zap)) and (.per_rate | mode($zap));

exactly(["exported_at", "sql_sha256", "excluded", "baseline", "m1", "zap_as_built"])
and (.exported_at | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
and (.sql_sha256 | type == "string" and test("^[0-9a-f]{64}$"))
and (.excluded | exactly(["null_tax_value", "null_shipping_rate", "unmapped_rate", "ireland_remap", "null_original_shipping"]) and all(.[]; count))
and (.baseline | count)
and (.m1 | variant(false))
and (.zap_as_built | variant(true))
