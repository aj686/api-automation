// Shrinks a Postman CLI 1.71 JSON report before PHP reads it (decisions.md D-037).
//
//   node scripts/compact-postman-report.mjs <report.json> <out.json> <maxBodyBytes>
//
// The CLI stores every response body as {type: "Buffer", data: [byte, byte, …]},
// so a 1 MB body becomes a ~20 MB report; PHP's json_decode then needs ~70 MB
// and the worker's limit is 128 MB. Here each body becomes text, cut at
// maxBodyBytes (measured: 20 MB → 78 KB in 0.16 s). Bodies that are not valid
// UTF-8 are not kept, only their size. Nothing else in the report changes, and
// no value is redacted here — the PHP parser does that.
import { readFileSync, writeFileSync } from 'node:fs';

const [input, output, maxArg] = process.argv.slice(2);
const max = Number(maxArg);
if (!input || !output || !Number.isInteger(max) || max <= 0) {
  console.error('usage: compact-postman-report.mjs <report.json> <out.json> <maxBodyBytes>');
  process.exit(2);
}

const utf8 = new TextDecoder('utf-8', { fatal: true });

const toText = (bytes) => {
  try {
    utf8.decode(bytes);
  } catch {
    return { text: null, binary: true };
  }
  // Cut on a character boundary: decode the prefix leniently, drop a split last character.
  const cut = bytes.subarray(0, max);
  const text = new TextDecoder('utf-8').decode(cut).replace(/�$/, '');
  return { text, binary: false };
};

const report = JSON.parse(readFileSync(input, 'utf8'));

for (const execution of report?.run?.executions ?? []) {
  const response = execution?.response;
  const stream = response?.stream;
  if (!stream || stream.type !== 'Buffer' || !Array.isArray(stream.data)) continue;

  const bytes = Buffer.from(stream.data);
  const { text, binary } = toText(bytes);
  delete response.stream;
  response.body = text;
  response.bodyBytes = bytes.length;
  response.bodyBinary = binary;
  response.bodyTruncated = !binary && bytes.length > max;
}

writeFileSync(output, JSON.stringify(report));
