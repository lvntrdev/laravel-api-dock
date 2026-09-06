import { describe, expect, it } from 'vitest'

import { groupOperations, groupOperationsByCategory } from '@/lib/operations'
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

describe('groupOperationsByCategory', () => {
  it('returns one unnamed category when the spec has no x-tagGroups', () => {
    const categories = groupOperationsByCategory(document)

    expect(categories).toHaveLength(1)
    expect(categories[0].name).toBeNull()
    expect(categories[0].groups.map((group) => group.tag)).toEqual(['Admin', 'Untagged', 'Users'])
  })

  it('buckets tags under x-tagGroups and puts leftovers under an empty-name "Other" category', () => {
    const grouped: OpenApiDocument = {
      ...document,
      'x-tagGroups': [{ name: 'Web API', tags: ['Users'] }],
    }

    const categories = groupOperationsByCategory(grouped)

    expect(categories.map((category) => category.name)).toEqual(['Web API', ''])
    expect(categories[0].groups.map((group) => group.tag)).toEqual(['Users'])
    expect(categories[1].groups.map((group) => group.tag)).toEqual(['Admin', 'Untagged'])
  })

  it('drops an x-tagGroups entry that matches no operation', () => {
    const grouped: OpenApiDocument = {
      ...document,
      'x-tagGroups': [{ name: 'Mobile API', tags: ['DoesNotExist'] }],
    }

    const categories = groupOperationsByCategory(grouped)

    expect(categories.map((category) => category.name)).toEqual([''])
  })
})
