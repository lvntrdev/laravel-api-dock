import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import JsonTree from '@/components/JsonTree.vue'
import { t } from '@/lib/i18n'

describe('JsonTree', () => {
  it('flattens nested objects in order with 16px depth indentation', () => {
    const wrapper = mount(JsonTree, {
      props: {
        value: {
          person: {
            name: 'Ada',
            age: 37,
          },
          active: true,
        },
      },
    })

    const rows = wrapper.findAll('.json-tree__row')

    expect(rows.map((row) => row.find('.json-tree__value').text())).toEqual([
      '{',
      '{',
      '"Ada",',
      '37',
      '},',
      'true',
      '}',
    ])
    expect(rows.map((row) => row.attributes('data-depth'))).toEqual([
      '0',
      '1',
      '2',
      '2',
      '1',
      '1',
      '0',
    ])
    expect(rows[2]?.attributes('style')).toContain('margin-left: 32px')
  })

  it('collapses and restores an object node', async () => {
    const wrapper = mount(JsonTree, {
      props: {
        value: { person: { name: 'Ada' }, active: true },
      },
    })

    const personRow = wrapper.find('[data-path="$/person"]')
    await personRow.find('button').trigger('click')

    expect(wrapper.find('[data-path="$/person"] .json-tree__value').text()).toBe('{ … },')
    expect(wrapper.find('[data-path="$/person/name"]').exists()).toBe(false)
    expect(wrapper.find('[data-path="$/person"] i').classes()).toContain('pi-chevron-right')

    await wrapper.find('[data-path="$/person"] button').trigger('click')

    expect(wrapper.find('[data-path="$/person/name"]').exists()).toBe(true)
  })

  it('renders array entries without keys', () => {
    const wrapper = mount(JsonTree, {
      props: { value: ['first', 2] },
    })

    expect(wrapper.find('[data-path="$/0"] .json-tree__label').exists()).toBe(false)
    expect(wrapper.find('[data-path="$/1"] .json-tree__label').exists()).toBe(false)
    expect(wrapper.findAll('.json-tree__key')).toHaveLength(0)
  })

  it('uses distinct syntax colours for string and number leaves', () => {
    const wrapper = mount(JsonTree, {
      props: { value: { label: 'dock', count: 3 } },
    })

    expect(wrapper.find('[data-path="$/label"] .json-tree__value').classes())
      .toContain('json-tree__value--string')
    expect(wrapper.find('[data-path="$/count"] .json-tree__value').classes())
      .toContain('json-tree__value--number')
  })

  it('renders malformed source as a raw pre fallback', () => {
    const source = '{"broken": }'
    const wrapper = mount(JsonTree, { props: { source } })

    expect(wrapper.element.tagName).toBe('PRE')
    expect(wrapper.classes()).toContain('json-tree--fallback')
    expect(wrapper.text()).toBe(source)
  })

  it('caps rendered rows and emits the localized truncation notice', () => {
    const wrapper = mount(JsonTree, {
      props: { value: Array.from({ length: 2500 }, (_, index) => index) },
    })

    const rows = wrapper.findAll('.json-tree__row')

    expect(rows).toHaveLength(2000)
    expect(rows.at(-1)?.text()).toContain(t('common.showingFirst', { limit: 2000 }))
  })
})
