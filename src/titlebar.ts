/**
 * The title-bar provider.
 *
 * OpenStation runs every title-bar provider script at boot, so the button can
 * paint on a window restored from the last session. That makes this the one
 * builder-related script every station load pays for, so it carries only the
 * preview button and nothing of the builder itself.
 */

import { registerPreviewButton } from './preview-button';

registerPreviewButton();
