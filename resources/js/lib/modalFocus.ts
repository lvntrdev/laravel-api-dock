export const MODAL_FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'

/**
 * Keeps the tab ring inside an open dialog. The overlay hides the page behind it, so a
 * Tab that walked out would move focus to a control nobody can see; `aria-modal` only
 * tells assistive technology that the rest of the page is inert, keeping the keyboard
 * inside is this function's job. Call it for every Tab keydown while the dialog is open.
 */
export function keepTabInside(event: KeyboardEvent, panel: HTMLElement): void {
  const focusable = Array.from(
    panel.querySelectorAll<HTMLElement>(MODAL_FOCUSABLE_SELECTOR),
  ).filter((element) => element.offsetParent !== null || element === document.activeElement)

  if (focusable.length === 0) {
    event.preventDefault()
    return
  }

  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  const active = document.activeElement
  const outside = !(active instanceof HTMLElement) || !panel.contains(active)

  if (event.shiftKey && (outside || active === first)) {
    event.preventDefault()
    last.focus()
    return
  }

  if (!event.shiftKey && (outside || active === last)) {
    event.preventDefault()
    first.focus()
  }
}
