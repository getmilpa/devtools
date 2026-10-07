---
name: governed-authoring
description: Use the moment a task needs you to WRITE or CHANGE code whose form still has to be decided — an operation an agent works with, a service, an entity, a business rule, a plugin — and your current tools only read, declare screens, or wire capabilities. (If the whole form already exists as a known composition, that is recipe:apply's job, not this skill's.) Scaffold with make, promote the trial, declare the test before the body, and judge behavior against it.
requires: make, implement
---

# governed-authoring

> The tool can prove the file is real. Only a test you declared can prove it is right.

The authoring front of the D³ loop for code. `governed-discovery` framed the question; this is how you
answer it *in code* without smuggling in the oldest lie — "it exists, therefore it works."

**Announce:** "Running governed-authoring — I need to write code, so I'll scaffold, promote, and lead with the test."

## When this fires

Any moment the next honest step is to **write or change code** — an operation, a service, an entity, a
business rule, a whole plugin — and the tools you hold only *read*, *declare screens*, or *wire
capabilities*. The urge to say "I can't build that, my tools don't reach it" is exactly when this
earns its minute: the surface **grows**, it is not fixed.

## The two rules

**1. Declare the test before you implement the body.** The authoring tools verify that a file is
syntactically real, correctly named, and namespaced — and they say just as plainly that *behavior is
unjudged when no test declares what the class must do*. A body with no test is structure, not evidence.

**2. Nothing you author is in the house until its trial is promoted.** Every authoring call — `make`,
`implement`, `edit`, registering a plugin — runs in a **trial**: a copy of the house. Its result says
`ran_in_trial` and names a `workspace`. The house does not have that work until you call
`sandbox:promote` with that workspace. So:

- **Promote a scaffold before you fill it.** `implement` and `edit` land only on a class the HOUSE has.
  `make` → `sandbox:promote` → `implement` → `sandbox:promote`. Filling a class whose trial you did not
  promote is refused: promote that trial, then send the call again.
- **Promote one `make` before the next** when both touch the same plugin. A trial is a copy taken when
  it started; a promotion over a file that moved since is refused.
- **Discard a trial you will not use** (`sandbox:discard`) instead of leaving it pending.

## The loop

0. **Choose the shape by WHO WILL USE IT — then let `make` build that shape whole.**
   > **Authoring creates a form that still needs criterion. A recipe applies a form whose criterion
   > was already paid.**

   If the whole form already exists as a known composition, you are on the WRONG skill: that is
   `recipe:apply`'s job. This skill is for a form whose shape *still has to be decided* — which
   entities, what fields, which invariants, which operations. Decide it, then pick the scaffold by its
   consumer:

   | who will use it | scaffold | what the house gains |
   |---|---|---|
   | **an agent, the terminal, MCP — something to WORK with** | `make what=operation` | an operation in the catalogue, with its contract |
   | a visitor reading a page | `make what=page` | a screen, declared with `screen:declare` |
   | an HTTP client | `make what=controller`, `crud` or `resource` | routes |
   | other code in this plugin | `make what=service`, `entity` | a class the plugin wires |

   **What a request asks the house to be able to DO is a verb, and each verb is an operation**, one
   `make` each. A controller serves a route; it does not give an agent anything to call. An operation
   over stored rows names their entity (`entity=<Entity>`) and its `run()` receives that entity's
   repository; its `operation=domain:verb` names it and fixes the scope it spends (`domain:write`, or
   `domain:read` with `reads=true`); `fields` are its input.

   **Having decided WHAT, do not hand-type what a governed op builds whole.** Hand-author (`implement`,
   below) ONLY the criterion no op can know: the **business rule**, the validation, the **domain
   invariant** — never the skeleton a scaffold already lands.

1. **Look for the authoring tools first — then grow the surface only if they are missing.**
   The loop is `make` / `implement` / `edit` (from **`milpa/devtools`**). If they are already in your
   tools, skip to step 3. Only if they are absent: run `capabilities`, and enable `milpa/devtools`.
   **The tools load on your NEXT turn, not this one** — enabling installs a package the running process
   cannot autoload mid-turn. That is not a failure: enable it, stop, and continue.

2. **Let the gate govern the growth — do not fight it.**
   Enabling a capability, and writing in a plugin you were not granted, pause for a person. That pause
   is the safety, not an obstacle. Ask for it in one clear line; when it lands, continue.

3. **Declare the evidence FIRST — `make what=test`.**
   Before you write a line of the body, scaffold the test that says what the code must *do* — the
   observable criterion, the falsifier. Promote it, then write it.

4. **Scaffold the structure — `make`, then promote.**
   `make what=plugin|page|controller|entity|crud|resource|service|operation|tool|test` writes real,
   conformant, auto-wired structure: an operation is registered in its plugin, a route in its plugin's
   routes. The skeleton is not the achievement; it is the honest starting shape. A scaffolded
   operation's body answers `ok: false` until you write it — it never claims a result it did not produce.

5. **Write the body — `implement`, then promote.**
   `implement` takes the COMPLETE file and lands it only after verifying syntax, `strict_types`, the
   class name, and the namespace its location dictates. Start from the scaffold: keep its declaration,
   write its body. Use `edit` for exact find-replace refinements.

6. **Judge the behavior against the test you declared.**
   `implement` verifies the file is real and says outright: *behavior unjudged*. So close that gap —
   run the test from step 3; for an operation, call it and read what it answers. Only now may you say
   what the code *does*, and only as far as the test proves it.

## The presence check (this is the whole test)

Read what you are about to call "done." If the only thing that changed is that files now *exist* —
a scaffolded class, a filled body, a green build — and no test you declared says what they must do,
**you have structure, not evidence.** And if a trial is still pending, **the house does not have it.**

If this skill made you write the test before the body, promote a trial you were about to build on, or
shrink "it works" to "it lands, behavior unjudged" — it worked. If it changed nothing, say so plainly.

## What this refuses

- It never fills a scaffold whose trial it has not promoted, and never calls work done while its trial
  is pending.
- It never reaches for a controller when what was asked for is something to work with — that is an
  operation.
- It never hand-types the whole shape a governed op builds; it hand-authors only the domain criterion.
- It never treats a scaffolded skeleton or a landed body as proof the code is correct.
- It never writes the implementation before the test that would judge it exists.
- It never routes around a gate — the surface grows *through* authorization, not around it.
- It never claims behavior the declared test does not cover.

## Pairs with

`governed-discovery` — frames the question before any of this. `governed-close` — at the end, settles
what the landed code actually proved against the test, and names what it did not.

---

> **Status: hypothesis, not settled doctrine.** It claims that a disciplined authoring loop — scaffold,
> promote, declare the test before the body, judge behavior against it — prevents the "I wrote it,
> therefore it works" that scaffolding invites. Its success test is the presence check. Corrected after
> a measured run (greenhouse evidence/1127): three residents out of three stopped at a scaffold they had
> not promoted or at an operation no scaffold made.
>
> Apache-2.0 · © Rodrigo Vicente — TeamX Agency
