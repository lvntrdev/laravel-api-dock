import { isReference, resolvePointer } from '@/lib/schema'
import type {
  OpenApiDocument,
  OperationEntry,
  ParameterObject,
  ServerObject,
} from '@/types/openapi'

export interface EditableParameter extends ParameterObject {
  value: string
}

export interface TryItProfile {
  id: string
  label: string
  base_url: string
  scheme: 'bearer' | 'basic' | 'header'
  credential_header: string | null
  credential_hint: string
}

export interface CurlState {
  method: string
  baseUrl: string
  path: string
  query: Readonly<Record<string, string>>
  headers: Readonly<Record<string, string>>
  body?: unknown
  profile?: TryItProfile
}

export function operationParameters(
  document: OpenApiDocument,
  entry: OperationEntry,
): EditableParameter[] {
  return entry.parameters.flatMap((candidate) => {
    const parameter = isReference(candidate)
      ? resolvePointer(document, candidate.$ref)
      : candidate

    if (!parameter || isReference(parameter) || !('name' in parameter) || !('in' in parameter)) {
      return []
    }

    const typed = parameter as ParameterObject
    const schema = typed.schema && !isReference(typed.schema) ? typed.schema : undefined
    const initial = typed.example ?? schema?.example ?? schema?.default

    return [{ ...typed, value: initial === undefined ? '' : String(initial) }]
  })
}

export function operationServers(
  document: OpenApiDocument,
  entry: OperationEntry,
): ServerObject[] {
  return entry.operation.servers?.length ? entry.operation.servers : (document.servers ?? [])
}

/**
 * Puts the origin the docs are actually being read from at the head of the
 * server list.
 *
 * A spec's `servers` is built once from the app URL, so on a multi-tenant host
 * it names the apex (`https://app.test/api`) no matter which tenant subdomain
 * you opened the page on. Sending the try-it request to that apex asks the wrong
 * server. The page's own origin is the one the reader demonstrably means.
 *
 * Only a server whose host is the same site is rewritten — equal, or one a
 * subdomain of the other. An unrelated host stays untouched: a spec that
 * deliberately points at a different service is not this window's to redirect.
 * A templated authority (`https://{tenant}.app.test`) is left alone too, since
 * its variables already give the reader the choice.
 */
export function withCurrentOrigin(servers: ServerObject[], origin: string): ServerObject[] {
  const current = safeUrl(origin)

  if (!current) {
    return servers
  }

  const rewritten: ServerObject[] = []
  const seen = new Set(servers.map((server) => server.url))

  for (const server of servers) {
    const url = localServerUrl(server, current)

    if (!url || seen.has(url)) {
      continue
    }

    seen.add(url)
    rewritten.push({ ...server, url, description: 'This site' })
  }

  return [...rewritten, ...servers]
}

function localServerUrl(server: ServerObject, current: URL): string | null {
  // A relative server ('/api') is already this origin — make it explicit so the
  // proxy receives an absolute target instead of a path it cannot validate.
  if (server.url.startsWith('/')) {
    return current.origin + server.url.replace(/\/$/, '')
  }

  const parsed = safeUrl(server.url)

  if (!parsed || parsed.host.includes('{') || !sameSite(parsed.host, current.host)) {
    return null
  }

  return current.origin + parsed.pathname.replace(/\/$/, '') + parsed.search
}

function sameSite(a: string, b: string): boolean {
  return a === b || a.endsWith(`.${b}`) || b.endsWith(`.${a}`)
}

function safeUrl(value: string): URL | null {
  try {
    return new URL(value)
  } catch {
    return null
  }
}

export function initialServerVariables(server?: ServerObject): Record<string, string> {
  return Object.fromEntries(
    Object.entries(server?.variables ?? {}).map(([name, variable]) => [name, variable.default ?? '']),
  )
}

export function resolveServerPreview(
  template: string,
  variables: Readonly<Record<string, string>>,
): string {
  return template.replace(/\{([^{}]+)\}/g, (placeholder, name: string) =>
    Object.hasOwn(variables, name) ? encodeRfc3986(variables[name]) : placeholder,
  )
}

/**
 * The values the given server will actually accept.
 *
 * A value carried over from another server or operation is only kept when this server
 * declares it and, for an enum, still allows it. Both the preview and the proxy payload
 * resolve through this, so the URL on screen is the URL the request goes to.
 */
export function narrowServerVariables(
  server: ServerObject | undefined,
  values: Readonly<Record<string, string>>,
): Record<string, string> {
  return Object.fromEntries(
    Object.entries(server?.variables ?? {}).map(([name, definition]) => [
      name,
      Object.hasOwn(values, name)
        && (definition?.enum === undefined || definition.enum.includes(values[name]))
        ? values[name]
        : definition?.default ?? '',
    ]),
  )
}

export function substitutePathParameters(
  path: string,
  parameters: readonly EditableParameter[],
): string {
  const values = new Map(
    parameters
      .filter((parameter) => parameter.in === 'path')
      .map((parameter) => [parameter.name, parameter.value]),
  )

  return path.replace(/\{([^{}]+)\}/g, (placeholder, name: string) => {
    const value = values.get(name)
    return value === undefined || value === '' ? placeholder : encodeURIComponent(value)
  })
}

export function parameterRecord(
  parameters: readonly EditableParameter[],
  location: 'query' | 'header',
): Record<string, string> {
  return Object.fromEntries(
    parameters
      .filter((parameter) => parameter.in === location && parameter.value !== '')
      .map((parameter) => [parameter.name, parameter.value]),
  )
}

export function buildCurlSample(state: CurlState): string {
  const url = appendQuery(joinUrl(state.baseUrl, state.path), state.query)
  const headers = { ...state.headers }

  if (state.profile) {
    const headerName =
      state.profile.scheme === 'header'
        ? state.profile.credential_header || 'Authorization'
        : 'Authorization'
    const prefix = state.profile.scheme === 'bearer'
      ? 'Bearer '
      : state.profile.scheme === 'basic'
        ? 'Basic '
        : ''
    headers[headerName] = `${prefix}${state.profile.credential_hint}`
  }

  if (state.body !== undefined && !hasHeader(headers, 'content-type')) {
    headers['Content-Type'] = 'application/json'
  }

  const parts = [`curl --request ${state.method.toUpperCase()}`, shellQuote(url)]

  for (const [name, value] of Object.entries(headers)) {
    parts.push(`--header ${shellQuote(`${name}: ${value}`)}`)
  }

  if (state.body !== undefined) {
    parts.push(`--data-raw ${shellQuote(JSON.stringify(state.body) ?? 'null')}`)
  }

  return parts.join(' \\\n  ')
}

function joinUrl(baseUrl: string, path: string): string {
  if (baseUrl === '') {
    return path
  }

  return `${baseUrl.replace(/\/$/, '')}/${path.replace(/^\//, '')}`
}

function appendQuery(url: string, query: Readonly<Record<string, string>>): string {
  const search = new URLSearchParams(query).toString()

  if (search === '') {
    return url
  }

  return `${url}${url.includes('?') ? '&' : '?'}${search}`
}

function shellQuote(value: string): string {
  return `'${value.replace(/'/g, `'"'"'`)}'`
}

function hasHeader(headers: Readonly<Record<string, string>>, name: string): boolean {
  return Object.keys(headers).some((header) => header.toLowerCase() === name)
}

function encodeRfc3986(value: string): string {
  return encodeURIComponent(value).replace(/[!'()*]/g, (character) =>
    `%${character.charCodeAt(0).toString(16).toUpperCase()}`,
  )
}
