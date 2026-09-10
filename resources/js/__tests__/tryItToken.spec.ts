import { describe, expect, it } from 'vitest'

import { extractToken, hasSecretKey, redactTokens } from '@/lib/tryItToken'

describe('extractToken', () => {
  it('reads a flat token field', () => {
    expect(extractToken('{"access_token":"abc.def"}')).toBe('abc.def')
  })

  it('walks a data envelope', () => {
    expect(extractToken('{"data":{"token":"nested"}}')).toBe('nested')
  })

  it('prefers the named field over a sibling envelope', () => {
    expect(extractToken('{"data":{"token":"nested"},"access_token":"flat"}')).toBe('flat')
  })

  it('follows an object-shaped token field', () => {
    expect(extractToken('{"access_token":{"token":"inner"}}')).toBe('inner')
  })

  it('returns null when no token field is present', () => {
    expect(extractToken('{"user":{"id":1,"email":"a@b.test"}}')).toBeNull()
  })

  it('returns null for a non-JSON body', () => {
    expect(extractToken('<html>login</html>')).toBeNull()
  })

  it('ignores an empty or oversized value', () => {
    expect(extractToken('{"token":""}')).toBeNull()
    expect(extractToken(JSON.stringify({ token: 'x'.repeat(4_001) }))).toBeNull()
  })
})

describe('redactTokens', () => {
  it('masks a nested token and leaves the rest of the body readable', () => {
    const redacted = redactTokens('{"data":{"access_token":"abc.def","user":{"id":1}}}')

    expect(JSON.parse(redacted)).toEqual({ data: { access_token: '***', user: { id: 1 } } })
  })

  it('masks a credential carried inside a list', () => {
    const redacted = redactTokens('{"items":[{"name":"ci","api_key":"live-key-1"}]}')

    expect(redacted).not.toContain('live-key-1')
    expect(JSON.parse(redacted)).toEqual({ items: [{ name: 'ci', api_key: '***' }] })
  })

  it('returns a non-JSON body unchanged', () => {
    expect(redactTokens('<html>login</html>')).toBe('<html>login</html>')
  })

  it('masks a credential deeper than the token discovery walk reaches', () => {
    const deep = '{"data":{"items":[{"credentials":{"oauth":{"access_token":"secret-deep"}}}]}}'

    expect(redactTokens(deep)).not.toContain('secret-deep')
  })

  it('keeps nothing of a JSON body that cannot be parsed', () => {
    // A truncated payload: the token is complete, the JSON around it is not.
    expect(redactTokens('{"access_token":"secret-cut","profile":{"na')).toBe('')
    expect(redactTokens('  [{"api_key":"k"')).toBe('')
  })
})

describe('hasSecretKey', () => {
  it('reports a password field a request body carries, at any walked depth', () => {
    expect(hasSecretKey('{"user":{"credentials":{"Password":"hunter2"}}}')).toBe(true)
    expect(hasSecretKey('{"a":{"b":{"c":{"d":{"e":{"password":"deep"}}}}}}')).toBe(true)
    expect(hasSecretKey('{"q":"laptop","page":2}')).toBe(false)
    expect(hasSecretKey('not json')).toBe(false)
  })

  it('discards a body nested deeper than the walk can follow', () => {
    // Valid JSON that `JSON.parse` handles, but deeper than the recursive walk's
    // stack: it cannot be proven clean, so nothing of it is kept.
    const deep = `${'['.repeat(20_000)}0${']'.repeat(20_000)}`

    expect(redactTokens(deep)).toBe('')
    expect(hasSecretKey(deep)).toBe(true)
  })
})
