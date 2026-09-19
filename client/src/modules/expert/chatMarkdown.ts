import DOMPurify from 'dompurify'
import MarkdownIt from 'markdown-it'

const markdown = new MarkdownIt({ html: false, linkify: false, breaks: true })

markdown.validateLink = (url: string) => {
  const value = url.trim().toLowerCase()
  return value.startsWith('/') && !value.startsWith('//')
    || value.startsWith('#')
    || /^(https?:|mailto:)/.test(value)
}

export function renderExpertAssistantMarkdown(source: string): string {
  const html = markdown.render(source)
  // markdown-it escapes raw HTML; the browser additionally sanitizes the rendered tree.
  if (typeof window === 'undefined') return html
  return DOMPurify.sanitize(html, {
    USE_PROFILES: { html: true },
    FORBID_TAGS: ['img', 'style'],
    ALLOWED_URI_REGEXP: /^(?:(?:https?|mailto):|\/(?!\/)|#)/i,
  })
}
