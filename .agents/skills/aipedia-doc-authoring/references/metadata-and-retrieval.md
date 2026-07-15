# Metadata and lexical retrieval reference

## Contents

- Metadata schema
- Field-writing rules
- Retrieval model
- Taxonomy and bilingual policy
- Migration policy
- Source rationale

## Metadata schema

Every topic document starts at byte zero with this constrained YAML frontmatter:

```yaml
---
description: "Explains when circuit breakers prevent cascading failures, how their states change, and when retry or timeout is a better fit."
tags:
  - reliability
  - distributed-systems
keywords:
  - circuit breaker
  - cascading failure
  - failure threshold
  - half open
  - resilience pattern
---
```

Only these three fields belong in document routing metadata unless the schema and validator are intentionally revised together.

| Field | Required | Constraints | Retrieval role |
| --- | --- | --- | --- |
| `description` | yes | Localized, 40–300 characters; explain what and when useful | Intent match and recommendation text |
| `tags` | yes | 2–6 unique lowercase kebab-case taxonomy terms | Broad category/filter signal |
| `keywords` | yes | 4–15 unique localized phrases, lowercase preferred | Acronyms, aliases, variants, user vocabulary |

Infer stable identity from the relative path without the `_en.md`/`_id.md` suffix. Infer locale from the suffix and use the first H1 as the localized display title. Do not duplicate identity, locale, or title as frontmatter fields.

## Field-writing rules

### Description

Write one or two natural sentences that answer both:

1. What question or task does this document resolve?
2. When should a reader or agent retrieve it?

Prefer user intent over implementation trivia. Include distinguishing boundaries when adjacent topics are easy to confuse. Do not list keywords mechanically.

### Tags

Use tags for stable taxonomy, not synonyms. Prefer existing values from neighboring documents and category paths. Examples include `reliability`, `security`, `architecture`, `anti-patterns`, `protocols`, `infrastructure`, and `observability`.

Create a new tag only when it describes a reusable category shared by several documents. Use the same tags in the English and Indonesian pair.

### Keywords

Use keywords for terms users may type but the title may not contain:

- acronyms and expanded forms;
- accepted aliases and former names;
- common spelling or hyphenation variants;
- concrete symptoms or intent phrases;
- localized technical vocabulary.

Avoid generic terms such as `guide`, `documentation`, `software`, or `best practice` unless they distinguish the topic. Never add an entity not actually covered by the body.

## Retrieval model

Use deterministic multi-field lexical ranking before introducing embeddings. Normalize Unicode case, punctuation, whitespace, and hyphen variants. Score exact phrases and fields separately, then combine them.

Recommended relative boosts:

| Match | Relative weight |
| --- | ---: |
| Exact canonical filename slug | 12 |
| Exact H1 phrase | 12 |
| Exact keyword phrase | 10 |
| H1 term | 8 |
| Keyword term | 6 |
| Exact tag | 5 |
| Description term | 3 |
| Heading term | 2 |
| Body term | 1 |

Treat these numbers as an initial ranking profile, not universal constants. Evaluate with realistic positive and near-miss queries before changing them. Metadata must only promote documents whose body contains the supporting answer.

For longer corpora, prefer BM25-style term-frequency normalization and per-field boosts. Add embeddings only when lexical evaluation shows recurring semantic misses that curated descriptions and keywords cannot reasonably cover.

## Taxonomy and bilingual policy

- Keep canonical path, section order, claims, and tags equivalent across the pair.
- Localize descriptions and keywords; do not mechanically copy English query vocabulary into Indonesian when users phrase it differently.
- Keep established engineering terms in English when Indonesian practitioners normally use them that way.
- A query may retrieve either locale, but the answer should prefer the user's locale when both files exist.

## Migration policy

Do not require a corpus-wide metadata migration to publish one well-formed new topic. Apply the schema to every new document and to existing documents when they receive substantive edits. Track bulk migration separately and validate it in batches.

The runtime must tolerate legacy documents without frontmatter until migration is complete. Parsed metadata should be stored as separate index fields; do not rely indefinitely on frontmatter being searchable only because it appears in raw body text.

## Source rationale

- The [Agent Skills specification](https://agentskills.io/specification) uses concise metadata for initial routing. This corpus adopts its intent-focused description principle while deriving identity from the document path instead of duplicating a `name` field.
- [Agent Skills description guidance](https://agentskills.io/skill-creation/optimizing-descriptions) recommends intent-focused descriptions plus realistic positive and near-miss trigger evaluation.
- [Agent Skills authoring guidance](https://agentskills.io/skill-creation/best-practices) recommends concise core instructions, progressive disclosure, concrete templates, gotchas, and validation loops.
- [Elasticsearch multi-field search](https://www.elastic.co/docs/reference/query-languages/query-dsl/query-dsl-multi-match-query) documents per-field boosting and combining evidence from multiple analyzed fields; this supports weighting title, keywords, description, headings, and body differently.
