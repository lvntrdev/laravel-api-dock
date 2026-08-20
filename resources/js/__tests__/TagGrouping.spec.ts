import { describe, expect, it } from 'vitest'

import { groupOperations } from '@/lib/operations'
import type { OpenApiDocument } from '@/types/openapi'

const document: OpenApiDocument = {
  openapi: '3.1.0',
  paths: {
    '/users': {
      get: {
        operationId: 'listUsers',
        summary: 'List users',
        tags: ['Users'],
        responses: { '200': { description: 'OK' } },
      },
      post: {
        operationId: 'createUser',
        summary: 'Create user',
        tags: ['Users', 'Admin'],
        responses: { '201': { description: 'Created' } },
      },
    },
    '/health': {
      get: {
        summary: 'Health check',
        responses: { '200': { description: 'OK' } },
      },
    },
  },
}

describe('groupOperations', () => {
  it('groups operations by every declared tag and falls back to Untagged', () => {
    const groups = groupOperations(document)

    expect(groups.map((group) => group.tag)).toEqual(['Admin', 'Untagged', 'Users'])
    expect(groups.find((group) => group.tag === 'Users')?.operations).toHaveLength(2)
    expect(groups.find((group) => group.tag === 'Admin')?.operations[0].operation.operationId).toBe(
      'createUser',
    )
    expect(groups.find((group) => group.tag === 'Untagged')?.operations[0].path).toBe('/health')
  })

  it('searches across method, path, tag, summary, and operation id', () => {
    expect(groupOperations(document, 'POST')[0].operations[0].operation.operationId).toBe(
      'createUser',
    )
    expect(groupOperations(document, '/health')[0].tag).toBe('Untagged')
    expect(groupOperations(document, 'listUsers')[0].operations).toHaveLength(1)
    expect(groupOperations(document, 'missing')).toEqual([])
  })
})
