import type { Directive } from 'vue'

/**
 * `v-tooltip="'label'"` — a hover/focus label for a control whose meaning is only an icon.
 *
 * One element, appended to the document body and positioned with viewport coordinates.
 * A CSS-only `::after` on the control cannot work here: every card in this panel clips
 * its own overflow, so a label drawn inside the control's own box is cut off by whichever
 * ancestor happens to clip — a problem that moves rather than disappears when the same
 * control is used somewhere else.
 */
const MARGIN = 7

const EDGE = 8

const listeners = new WeakMap<HTMLElement, () => void>()

let node: HTMLElement | undefined

export const vTooltip: Directive<HTMLElement, string | undefined> = {
  mounted(element, binding) {
    element.dataset.tooltip = binding.value ?? ''

    const open = (): void => show(element)

    element.addEventListener('mouseenter', open)
    element.addEventListener('focus', open)
    element.addEventListener('mouseleave', hide)
    element.addEventListener('blur', hide)
    // A click has done what the control is for, so the label has said its piece.
    element.addEventListener('click', hide)

    listeners.set(element, () => {
      element.removeEventListener('mouseenter', open)
      element.removeEventListener('focus', open)
      element.removeEventListener('mouseleave', hide)
      element.removeEventListener('blur', hide)
      element.removeEventListener('click', hide)
    })
  },
  updated(element, binding) {
    element.dataset.tooltip = binding.value ?? ''

    // The label can change while it is on screen — a copy button turns into "copied".
    if (node?.dataset.for === elementId(element)) {
      node.textContent = element.dataset.tooltip
    }
  },
  unmounted(element) {
    listeners.get(element)?.()
    listeners.delete(element)

    if (node?.dataset.for === elementId(element)) {
      hide()
    }
  },
}

function show(element: HTMLElement): void {
  const label = element.dataset.tooltip ?? ''

  if (label === '') {
    hide()
    return
  }

  const tooltip = tooltipNode()
  tooltip.textContent = label
  tooltip.dataset.for = elementId(element)
  tooltip.classList.add('app-tooltip--visible')

  const anchor = element.getBoundingClientRect()
  const box = tooltip.getBoundingClientRect()
  // Below the control by default, above it when the viewport has no room left.
  const below = anchor.bottom + MARGIN
  const top = below + box.height > window.innerHeight ? anchor.top - MARGIN - box.height : below
  const centred = anchor.left + (anchor.width - box.width) / 2

  tooltip.style.top = `${Math.max(EDGE, top)}px`
  tooltip.style.left = `${Math.min(Math.max(EDGE, centred), window.innerWidth - box.width - EDGE)}px`

  // A tooltip placed in viewport coordinates goes stale the moment anything scrolls, so it
  // is dismissed instead of chased.
  window.addEventListener('scroll', hide, true)
}

function hide(): void {
  window.removeEventListener('scroll', hide, true)

  if (node) {
    node.classList.remove('app-tooltip--visible')
    delete node.dataset.for
  }
}

function tooltipNode(): HTMLElement {
  if (!node) {
    node = document.createElement('div')
    node.className = 'app-tooltip'
    node.setAttribute('role', 'presentation')
    document.body.append(node)
  }

  return node
}

let counter = 0

function elementId(element: HTMLElement): string {
  element.dataset.tooltipId ??= String(++counter)

  return element.dataset.tooltipId
}
