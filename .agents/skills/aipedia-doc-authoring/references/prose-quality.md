# Natural technical prose review

Use this checklist to make technical documentation sound deliberate and human without weakening precision. The document's purpose decides its register: reference material may be terse, tutorials may be conversational, and architecture guidance should be analytical.

## Drafting principles

- Start with the concrete problem, decision, or symptom. Avoid generic openings that merely announce the topic.
- Prefer specific actors, constraints, commands, and outcomes over abstract claims such as “improves efficiency”.
- Vary sentence and paragraph length naturally. Split a dense explanation when its reasoning changes, not at a fixed visual interval.
- Use headings when they help scanning. Do not create a section for every short thought or force every explanation into the same template.
- Use lists for genuinely parallel items. Do not force ideas into groups of three or repeat the same point in prose immediately before and after a list.
- Match transitions to the actual relationship between ideas. Remove canned transitions and repeated mini-conclusions.
- Explain trade-offs directly. State what gets worse, what assumption must hold, and when another approach is preferable.
- Keep examples plausible and tied to the surrounding claim. Do not invent products, entities, routes, or capabilities merely to make an example vivid.

## Bilingual review

- Preserve claims, boundaries, examples, and section intent across `_en` and `_id`.
- Localize phrasing instead of translating sentence structure mechanically.
- Keep established English engineering terms when Indonesian practitioners normally use them, but explain uncommon terms at first use.
- Let each language have natural rhythm; parity means equivalent meaning, not identical sentence counts.

## Final pass

Check that:

- the opening gives the reader useful information immediately;
- every factual or operational claim is supported by repository evidence or cited primary sources;
- paragraphs do not all share the same length or cadence;
- headings and lists improve navigation rather than decorate the page;
- the ending leaves the reader with a decision, result, or next step instead of restating the introduction;
- no repository path is presented as something an end user can open.
