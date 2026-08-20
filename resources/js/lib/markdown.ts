import DOMPurify from 'dompurify'
import { marked } from 'marked'

/**
 * Every `description` in an OpenAPI document is CommonMark — that is the
 * specification, not a convention. Rendering one as plain text does not merely
 * lose formatting: an author who wrote a collapsible `<details>` block or a
 * fenced code sample gets the raw markup dumped into a single paragraph.
 *
 * The document is data, and a document this viewer did not author may carry a
 * `<script>` or an `onerror=` just as easily as a heading, so the rendered HTML
 * goes through DOMPurify before it ever reaches `v-html`. Sanitising the OUTPUT
 * rather than the input is deliberate: it covers the raw HTML CommonMark lets
 * through as well as anything the renderer itself produces.
 */
marked.setOptions({
  gfm: true,
  breaks: false,
})

/**
 * Tags an API description legitimately uses, and nothing that can execute or
 * navigate on its own. `details`/`summary` are here because authors reach for
 * them to fold long agent prompts out of the way.
 */
const ALLOWED_TAGS = [
  'a', 'blockquote', 'br', 'code', 'del', 'details', 'div', 'em', 'h1', 'h2',
  'h3', 'h4', 'h5', 'h6', 'hr', 'img', 'li', 'ol', 'p', 'pre', 'span',
  'strong', 'summary', 'table', 'tbody', 'td', 'th', 'thead', 'tr', 'ul',
]

const ALLOWED_ATTR = ['align', 'alt', 'class', 'href', 'src', 'style', 'title']

export function renderMarkdown(source: string): string {
  const trimmed = source.trim()

  if (trimmed === '') {
    return ''
  }

  const html = marked.parse(trimmed, { async: false }) as string

  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
    // A description may not open a URL scheme that executes; `javascript:` and
    // `data:` are the two that turn a link into a payload.
    ALLOWED_URI_REGEXP: /^(?:https?:|mailto:|tel:|#|\/|\.{1,2}\/)/i,
  })
}
