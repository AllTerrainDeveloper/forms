# MIO response action buttons: Preview after creating a form

**Status: original proposal with an implementation follow-up below; shell changes are still unmerged.** Reviewed 14 September 2026 against [PR #816](https://github.com/WordPress/openstation/pull/816), commit `8eadeb9a0fe4f300114f7ab5a3f224b12f346d88`. This proposal is separate from the [validation/recovery review](mio-api-feedback.md).

## Implementation follow-up

The developer has now implemented `responseActions`, message/action IDs, a lease-local action registry and response rows in the live working checkout on top of `8eadeb9a`. These changes are still in the unmerged PR's working tree. Forms now supplies the **Preview** consumer through this optional API: it binds a confirmed receipt to the saved form ID, refreshes the authenticated preview URL on click and opens the native preview without saving again. Older shells ignore the optional callback.

The consumer's unit tests cover missing/unknown/forged confirmations, original-form binding across turns, permission errors and editor disposal. The optional conformance suite imports the developer's actual session/action registry and verifies that clicking Preview adds neither a model round nor a save. The Docker browser tests additionally exercise the consumer callback and the actual rendered chat: typing, two YAML repairs, one save, clicking Preview, reopening chat and Escape focus restoration. WordPress REST is real; only provider replies are simulated.

The design below records the handoff that prompted that implementation. Its proposed names describe the initial plan; the implementation in `src/mio/assistant.ts` is the Forms source of truth. The receipt map is reduced to the latest confirmed save for the current turn; each returned callback captures its own form ID, keeping memory bounded.

## Answer at the reviewed commit

MIO supports executing private abilities, displaying callouts, and opening its chat. It does **not** currently let an app attach clickable actions to an assistant reply. [MioChatMessage](https://github.com/WordPress/openstation/blob/8eadeb9a0fe4f300114f7ab5a3f224b12f346d88/src/mio/assistant/types.ts#L53) contains only role/text. [The chat renderer](https://github.com/WordPress/openstation/blob/8eadeb9a0fe4f300114f7ab5a3f224b12f346d88/src/mio/assistant/chat.ts#L83) renders Markdown into message bubbles and has no response-action row. Send, Stop, Close and Ask MIO are shell controls, not app-supplied response actions. A Markdown link is not a substitute for invoking the app's native preview window.

## User experience

After a confirmed form creation or update:

> Your form is saved as a draft. The Other option reveals a required text area.
>
> **[ Preview ]**

Clicking Preview opens or focuses the existing form preview window. No additional AI request, save, publication, permission dialog or form submission occurs. The button appears only after an authoritative save result; a failed validation cannot produce “Preview your new form.” If the outcome is unknown, reconcile it first or offer a separately labeled read-only status action.

Use one prominent action and at most two secondary actions. The row belongs to the assistant message, wraps on narrow screens, and uses the shell's existing button component, spacing and theme tokens. Keep assistant prose readable independently of the buttons.

## Recommended MVP: the app supplies actions after a turn

Add an optional responseActions callback to MioWindowContext. The shell calls it after it has constructed the final assistant message and turn summary. The app derives actions from actual confirmed operations, not the model's claim that it saved something. The callback returns descriptors and frontend callbacks; the shell stores callbacks in the window lease and writes only opaque action IDs into the conversation.

This MVP requires **no provider or PHP transport change**. Apps can supply useful buttons reliably even if the model never mentions an action. Existing registrations and custom conversation stores continue working because all new fields are optional.

Proposed types (names are suggestions):

```typescript
interface MioResponseAction {
    id: string;                       // Unique within this message.
    label: string;                    // Plain text, 1–40 characters.
    ariaLabel?: string;               // E.g. "Preview Contact us".
    icon?: string;                    // App-supplied approved icon name.
    emphasis?: 'primary' | 'secondary';
    effect: 'read' | 'navigate';       // MVP deliberately has no write actions.
    allowed?: () => boolean;          // Rechecked on render and immediately on click.
    run: (context: {
        signal: AbortSignal;
        messageId: string;
        turnId: string;
    }) => void | Promise<void>;
}

interface MioResponseContext {
    messageId: string;
    summary: MioTurnSummary;
    operations: readonly MioOperation[];  // This turn only; immutable snapshots.
}

interface MioWindowContext {
    responseActions?: (context: MioResponseContext) => readonly MioResponseAction[];
}

interface MioChatMessage {
    role: 'user' | 'assistant';
    text: string;
    id?: string;
    actionIds?: readonly string[];
}
```

Callbacks, nonce-bearing URLs and closures are never serialized into conversation history, provider requests or localStorage. Persisting a custom conversation store cannot recreate executable callbacks: after reload missing action IDs render no live controls. A callback failure cannot change a confirmed save into a failed save.

## Forms integration example

This is proposed consumer code, not code that works against today's API. The Forms adapter remembers only the saved form ID for each confirmed server receipt. The callback retrieves that ID from its own receipt map, rather than accepting an ID or URL written by the model.

```typescript
responseActions: ({ summary, operations }) => {
    if (summary.status !== 'completed' || summary.unknownWrites > 0) return [];

    const saved = [...operations].reverse().find(operation =>
        operation.ability === 'apply_form_edit' &&
        operation.status === 'confirmed' &&
        operation.receipt && savedFormsByReceipt.has(operation.receipt)
    );
    if (!saved?.receipt) return [];

    const formId = savedFormsByReceipt.get(saved.receipt)!;
    return [{
        id: 'preview-saved-form',
        label: 'Preview',
        ariaLabel: 'Preview the saved form',
        icon: 'dashicons-visibility',
        emphasis: 'primary',
        effect: 'navigate',
        allowed: () => editorRoot.isConnected && canEditForms(),
        run: async ({ signal }) => {
            // Extend the existing api.getForm wrapper to accept AbortSignal.
            // Refresh the nonced URL from authenticated WordPress, not AI output.
            const form = await api.getForm(formId, signal);
            signal.throwIfAborted();
            openPreviewWindow(form.id, form.title, form.previewUrl);
        },
    }];
}
```

Forms already has [openPreviewWindow](../src/preview-button.ts), which uses a per-form window ID and reuses an existing preview. Use it directly for this saved-form action. The higher-level openPreview helper saves dirty editor content first; that would turn a button labeled Preview into an extra write. The response button should open the **latest saved definition of the bound form**, not claim to reconstruct a historical revision or use whichever form happens to be selected later.

If the form was deleted or access was revoked, show a local action error and do not open another form. If the user has switched to another form within the same live editor, the old message's button still addresses its original form ID. Disposing the owning editor invalidates its action callbacks.

## Rendering and execution rules

1. After a turn settles, create a stable message ID and request descriptors once for that message. Do not run action callbacks during render or model execution.
2. Validate descriptors: at most three actions; unique nonempty IDs; bounded text; known emphasis/effect values. Treat labels as text, never HTML. An invalid descriptor is omitted without losing the assistant response or save receipt.
3. Store callbacks in a lease-local registry keyed by messageId/actionId. The conversation holds references only. Release them when messages are evicted or the lease is disposed, so callback memory cannot grow beyond the conversation bound.
4. Render the action row after the escaped Markdown. Reuse os-button; give the row an accessible group label such as “Suggested actions.” Enter/Space activate, Tab reaches each button, and pending/error state is announced politely.
5. On activation, recheck ownership/availability and disable that button while its promise is pending. Double-clicks cannot start two concurrent requests. Successful Preview can be clicked again later; the app's per-form window ID handles reuse.
6. Closing the chat cancels pending button work; reopening the same live conversation can restore its actions. Disposing the window removes callbacks permanently. For navigation actions, initiating the destination's focus change must not retroactively report failure after the action completed.
7. Display a failure next to the action and preserve the saved message. Do not send the error back to the provider or automatically rerun the action. A retry is another deliberate button click.
8. Preserve focus and scroll when button state changes. Today's paint routine replaces all bubbles; avoid rebuilding a focused action row, or restore its focus by stable message/action ID after repaint.

If an app uses a browser-tab fallback, preserve the user gesture needed by popup blockers (for example open a blank tab synchronously, then navigate after the authorized URL arrives; close it on failure). Forms inside OpenStation can use its native preview window directly.

## Optional next phase: model-selected registered actions

Once the MVP works, let an app advertise a catalog of action IDs/descriptions. The model may suggest **only IDs from that catalog** in a typed final response, while the app resolves the label, target and callback. Unknown/unavailable IDs are ignored or rejected. Do not interpret Markdown, URLs, JavaScript strings or arbitrary tool arguments as executable button definitions.

Keep this optional. “Preview after a confirmed save” does not require the model to decide whether a preview button exists. Destructive or write actions, if added later, need their own explicit effect/operation semantics and current server authorization; do not reuse the read/navigation MVP to smuggle saves into buttons.

## Files and implementation sequence

| Step | OpenStation files | Work |
|---|---|---|
| 1. Contract | src/mio/assistant/types.ts, src/public-api.ts | Optional descriptors, response context and message references |
| 2. Ownership | src/mio/assistant/session.ts, a small response-actions module | Lease-local registry, stable IDs, lifecycle and bounded cleanup |
| 3. UI | src/mio/assistant/chat.ts and chat styles | Action rows, pending/errors, keyboard behavior, focus-preserving repaint |
| 4. Examples | docs/mio-window-assistant.md, docs/examples/mio-form-editing.md | Preview example, disposal rules, custom store behavior |
| 5. Consumer | Forms src/mio/assistant.ts and src/preview-button.ts | Receipt-to-form map, authenticated preview URL refresh, native opener |
| 6. QA | Vitest and browser tests | Cases below, desktop/narrow window/RTL themes |

## Acceptance tests

- A successful create/update offers Preview bound to the confirmed form ID.
- Clicking Preview opens the correct native preview, with zero provider calls and zero save/submit requests.
- Invalid YAML, exhausted retries and unknown save outcomes do not offer a misleading success action.
- Duplicate clicks produce one in-flight action; repeated later clicks reuse the preview window.
- Editing/selecting another form does not retarget an old button.
- Deleted form or revoked permission gives a local error; no unintended navigation.
- Closing/reopening chat preserves eligible actions; disposing/reopening the editor removes stale callbacks.
- A custom stored conversation cannot revive an old callback after reload.
- Keyboard, screen-reader announcements, narrow layout, RTL, theme contrast and focus after repaint work.
- Unavailable/duplicate/overlong descriptors and hostile label text cannot break the message renderer.
- Registrations without responseActions behave exactly as before.

## Delivery boundary

The current Forms change can ship its YAML/MIO workflow while this API is developed. Do not inject custom buttons into the shell's private DOM or invent unsupported Markdown syntax. Once the API lands, the Forms consumer is small because the saved ID, receipt and preview opener already exist.
