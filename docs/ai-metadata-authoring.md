# Authoring instructions for AI agents

Point an assistant at this file when you ask it to document or re-document an API
operation. It is written to be executed, not skimmed: it says which fact belongs
in which place, what "finished" means, and how to check the result.

The mechanics — constructor signatures, repeatability, class/method merge order —
are in the README section *Authoring AI metadata as one operation contract*. This
file is the editorial contract on top of them.

---

## 1. The one rule everything else follows

An operation is described in **three** places, and a fact belongs to exactly one:

| Place | Holds | Never holds |
|---|---|---|
| **The OpenAPI schema** (FormRequest, Resource, return types) | Parameter names, types, required-ness, status codes, response shapes | Prose |
| **The docblock description** | Prose a human reads to understand what the endpoint is *for* | Parameter tables, status-code lists, response shapes, agent prompts, changelogs |
| **The attributes** (`AiHint`, `AiPitfall`, `AiExample`, `AiChangelog`, `AiTool`, `ApiFeature`) | The structured contract: what is misread, what breaks, what changed, what an agent may call | Anything already in the schema |

**Do not restate the schema in prose.** The panel builds the agent prompt and the
MCP tool definition from the schema, so a hand-written parameter table or status
list is duplicated content that goes stale independently of the code.

**Do not write an agent prompt into the description.** The AI panel generates one
from summary + description + hint + parameters + request body + responses +
pitfalls + examples + changelog. A hand-written prompt block in the description is
a second, worse copy of that, and the reader now has two prompts to choose between.

If you find such a block in a description or in an external guide file, its content
does not get deleted — it gets **moved into the attributes**, sentence by sentence,
using section 3.

---

## 2. Where each kind of fact goes

Read the sentence you are about to write and route it:

- States a rule the caller must obey, or names a mistake a caller will otherwise
  make → **`AiPitfall`**
- Names the single fact most likely to be misread on first contact →
  **`AiHint`** (exactly one per operation, one sentence)
- Shows a concrete request and the exact response it produces → **`AiExample`**
- Records that the contract *changed* on a date → **`AiChangelog`**
- Declares whether an agent may call this operation, and under what tool name →
  **`AiTool`**
- States auth guard, scopes, rate limit, deprecation or stability →
  **`ApiFeature`**
- Explains what the endpoint is for, in prose, to a human → **the docblock
  description**
- Describes a parameter or a field → **the schema** (FormRequest rule, Resource
  property, PHP type), not prose

A fact that applies to every operation in a controller goes on the **class**, not
repeated on each method. Class-level entries reach every method automatically.

---

## 3. What each attribute must contain

### `AiHint(string $hint)`

One sentence. The single thing a competent integrator gets wrong on first contact —
usually a success that looks like a failure, or a failure that looks like a success.

Not a summary of the endpoint. If the sentence would still be true after replacing
the endpoint with any other, it is not a hint.

```php
#[AiHint('An unrecognised barcode is a 200 answer with valid=false, not an error; only 404, 422 and 500 are failures.')]
```

### `AiPitfall(string $text, int $order = 0)`

One rule per entry. Imperative or declarative, never a paragraph. Each entry must
be independently actionable: a reader who obeys only that one line is measurably
less likely to ship a bug.

Write a pitfall when:

- two outcomes look alike and must be handled differently (`500` vs `valid:false`)
- a field is null in a legitimate state, and the caller will assume it never is
- a value crosses to another endpoint under a different name
- a value's meaning depends on state (a catalogue key in one case, free text in
  another)
- calling behaviour matters: batching, queueing, retry, polling, caching
- the scope is narrower than it looks (which header decides the tenant, what the
  payload deliberately omits)
- a limit exists and its key is not obvious (per IP? per key? per resource?)

Use `order` to group: shared class-level rules at 10, operation rules at 20,
edge cases at 30. Equal orders keep declaration order.

```php
#[AiPitfall('Each call is one request to the upstream system. One scan, one request — never queue or batch in the background.', order: 20)]
```

### `AiExample(string $name, array $request = [], array $response = [])`

Cover the branches a caller must handle, not the happy path alone. For most
endpoints that is three: the ordinary success, the success that looks like a
failure, and the interesting failure.

`name` describes the *state*, not the mechanics: "Known badge, person not imported
into this congress yet" beats "Example 2".

Both arrays hold real values — the shapes an actual call produces, with the same
key casing and the same nulls.

### `AiChangelog(string $date, string $summary, bool $breaking = false)`

**One entry per contract change, added at the moment of the change.** This is the
attribute agents most often forget: the code changes, the pitfalls are updated to
match, and no entry is added — so a reader holding an older integration has no way
to learn what moved.

- `date` is `Y-m-d`. Entries sort newest first; a malformed date sinks to the end.
- `summary` says what changed from the *caller's* point of view, not which class
  was edited. "Endpoint gated behind the barcode_entry module: a congress with the
  module off now answers 404" — not "Added module check".
- `breaking: true` when an existing correct integration stops working: a removed or
  renamed field, a newly required parameter, a status code that changes meaning, a
  narrowed enum. A new optional field is not breaking.
- Never rewrite or delete a past entry. History is append-only; a correction is a
  new entry.
- The first entry of an endpoint's life is its introduction.

### `AiTool(bool $enabled = true, ?string $name = null, ?string $description = null)`

`name` is a stable `snake_case` verb phrase; it is an identifier an agent will hold
across versions, so treat a rename as breaking. `description` is one sentence in the
imperative, describing what calling it *does*.

Set `enabled: false` on an operation an agent must not call autonomously — anything
destructive, expensive, or requiring a human decision. When
`api-dock.ai.mcp_opt_in` is true, only operations carrying this attribute are
exported at all.

### `ApiFeature(...)`

Only the fields that are true. A `null` field inherits the value derived from route
middleware or from the class attribute; passing a wrong value overrides a correct
derived one, so leave a field out unless you are asserting it.

---

## 4. Definition of done

An operation is finished when all of these hold:

1. Its docblock description is prose only — no parameter table, no status-code
   list, no agent prompt, no changelog.
2. It has exactly one `AiHint`.
3. Every rule in section 3's pitfall list that applies is present as its own
   `AiPitfall`.
4. Its `AiExample` set covers the ordinary success, any success that resembles a
   failure, and the interesting failure.
5. Every contract change has an `AiChangelog` entry, newest reachable from the
   date alone.
6. `AiTool` is present, with a decision recorded in `enabled`.
7. `ApiFeature` states auth, and any rate limit whose key is not obvious.
8. No fact appears in two of the three places from section 1.

---

## 5. Verifying the result

The document is the source of truth, not the source file — check what actually
came out:

```bash
php artisan api-dock:export --format=llms   # the agent-facing bundle
php artisan api-dock:diff                   # what changed against the stored snapshot
```

Then read the operation in the panel and confirm, in this order:

- the description renders as prose, and nothing structured is inside it
- the AI panel shows the hint, every pitfall, every example
- the generated agent prompt reads as a complete integration brief on its own
- the changelog lists every dated change, newest first

`api-dock:diff` is the check that catches the missing changelog entry: if it
reports a contract change that no `AiChangelog` entry mentions, the operation is
not finished.

---

## 6. Language

This package prescribes no language. Attribute text is written in whatever language
the project's own API documentation is written in — Turkish, German, English, any
other — and that choice belongs to the project, never to the assistant editing it.

Determine it, do not assume it: read the description and the attributes already on
the surface you are editing and match them. If a surface has none yet, match the
project's other API documentation; if there is none of that either, ask.

Two rules follow from this, and they are the only ones:

- **Do not translate what is already there.** Rewriting existing attributes into
  another language is a change nobody asked for, and it destroys the wording the
  team agreed on.
- **Do not introduce a second language into one surface.** An operation whose hint
  is English and whose pitfalls are Turkish reads as two half-finished documents,
  and the generated agent prompt inherits the seam.

Identifiers stay verbatim regardless: `AiTool` names, field names, header names,
status codes, enum values and code samples are part of the contract, not prose.
