import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import SchemaTree from '@/components/SchemaTree.vue'
import { t } from '@/lib/i18n'
import type { OpenApiDocument } from '@/types/openapi'

describe('SchemaTree', () => {
  it('truncates a circular reference instead of recursing forever', () => {
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

    const wrapper = mount(SchemaTree, {
      props: {
        document,
        schema: { $ref: '#/components/schemas/Node' },
        name: 'node',
      },
    })

    expect(wrapper.text()).toContain('label')
    expect(wrapper.text()).toContain(t('schema.circularReference'))
    expect(wrapper.findAll('.schema-node')).toHaveLength(3)
  })

  it('merges allOf properties and required markers', () => {
    const document: OpenApiDocument = {
      openapi: '3.1.0',
      paths: {},
      components: {
        schemas: {
          Base: {
            type: 'object',
            required: ['id'],
            properties: { id: { type: 'integer' } },
          },
        },
      },
    }

    const wrapper = mount(SchemaTree, {
      props: {
        document,
        schema: {
          allOf: [
            { $ref: '#/components/schemas/Base' },
            {
              type: 'object',
              required: ['name'],
              properties: { name: { type: 'string' } },
            },
          ],
        },
        name: 'resource',
      },
    })

    expect(wrapper.text()).toContain('id')
    expect(wrapper.text()).toContain('name')
    expect(wrapper.findAll('.schema-node__required')).toHaveLength(2)
  })

  it('renders enum values', () => {
    const wrapper = mount(SchemaTree, {
      props: {
        document: { openapi: '3.0.3', paths: {} },
        schema: { type: 'string', enum: ['draft', 'published'] },
        name: 'status',
      },
    })

    expect(wrapper.find('.schema-node__enum').text()).toContain('"draft"')
    expect(wrapper.find('.schema-node__enum').text()).toContain('"published"')
  })

  it.each([
    { type: 'string', nullable: true },
    { type: ['string', 'null'] },
  ])('renders nullable schemas declared as $type', (schema) => {
    const wrapper = mount(SchemaTree, {
      props: {
        document: { openapi: '3.1.0', paths: {} },
        schema,
        name: 'nickname',
      },
    })

    expect(wrapper.find('.schema-node__nullable').text()).toBe(t('schema.nullable'))
  })

  it('honors the configured depth limit for deeply nested inline schemas', () => {
    const wrapper = mount(SchemaTree, {
      props: {
        document: { openapi: '3.1.0', paths: {} },
        schema: {
          type: 'object',
          properties: {
            first: {
              type: 'object',
              properties: { second: { type: 'string' } },
            },
          },
        },
        name: 'root',
        maxDepth: 2,
      },
    })

    expect(wrapper.text()).toContain(t('schema.depthLimit'))
  })
})
