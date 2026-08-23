import { describe, expect, it } from 'vitest'

import { sampleFromSchema } from '@/lib/schema'
import type { OpenApiDocument } from '@/types/openapi'

const document: OpenApiDocument = {
  openapi: '3.1.0',
  paths: {},
  components: {
    schemas: {
      Node: {
        type: 'object',
        properties: {
          label: { type: 'string' },
          child: { $ref: '#/components/schemas/Node' },
        },
      },
    },
  },
}

describe('sampleFromSchema', () => {
  it('stops on a self-referencing schema instead of recursing forever', () => {
    expect(sampleFromSchema({ $ref: '#/components/schemas/Node' }, document))
      .toEqual({ label: '', child: null })
  })

  it('samples formats, enums, arrays and unions', () => {
    const sample = sampleFromSchema(
      {
        type: 'object',
        properties: {
          createdAt: { type: 'string', format: 'date-time' },
          status: { type: 'string', enum: ['draft', 'live'] },
          tags: { type: 'array', items: { type: 'string' } },
          score: { type: ['integer', 'null'] },
          active: { type: 'boolean' },
        },
      },
      document,
    )

    expect(sample).toEqual({
      createdAt: '2024-01-01T00:00:00Z',
      status: 'draft',
      tags: [''],
      score: 0,
      active: false,
    })
  })
})
