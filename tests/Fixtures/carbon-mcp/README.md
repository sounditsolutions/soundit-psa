# Carbon Connect MCP fixtures (card PEYvh4NL)

- `protected-resource.json` and `authorization-server.json` are Carbon's real,
  unauthenticated OAuth metadata, captured 2026-10-21 16:30Z from
  `/.well-known/oauth-protected-resource/mcp` and
  `/.well-known/oauth-authorization-server`. Byte-for-byte copies.
- `tools-list-page-1.json`, `tools-list-page-2.json` and `tools-list-after.json`
  are **synthetic**. Carbon's tool catalogue needs a signed-in session and had
  not been read when these were written, so they follow the MCP specification's
  `tools/list` result shape (name, title, description, inputSchema, annotations
  with readOnlyHint / destructiveHint / idempotentHint / openWorldHint) with
  invented `*_example` tool names. The first real catalogue sync after the
  admin sign-in is the live proof of the shape.
