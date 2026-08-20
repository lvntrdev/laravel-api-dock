import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import DiffView from '@/components/DiffView.vue'
import type { SpecDiffResult } from '@/types/diff'

describe('DiffView', () => {
  it('groups every change into the matching severity bucket', () => {
    const diff: SpecDiffResult = {
      has_breaking: true,
      changes: [
        {
          severity: 'breaking',
          path: '/users.get.responses.200',
          operation: 'GET /users',
          type: 'response_code_removed',
          description: 'Response 200 was removed.',
        },
        {
          severity: 'additive',
          path: '/users.post',
          operation: 'POST /users',
          type: 'operation_added',
          description: 'POST /users was added.',
        },
        {
          severity: 'cosmetic',
          path: '/users.get.summary',
          operation: 'GET /users',
          type: 'cosmetic_change',
          description: 'The summary changed.',
        },
      ],
    }

    const wrapper = mount(DiffView, { props: { diff } })
    const breaking = wrapper.get('[data-severity="breaking"]')
    const additive = wrapper.get('[data-severity="additive"]')
    const cosmetic = wrapper.get('[data-severity="cosmetic"]')

    expect(breaking.text()).toContain('Response 200 was removed.')
    expect(breaking.text()).not.toContain('POST /users was added.')
    expect(additive.text()).toContain('POST /users was added.')
    expect(additive.text()).not.toContain('The summary changed.')
    expect(cosmetic.text()).toContain('The summary changed.')
    expect(cosmetic.text()).not.toContain('Response 200 was removed.')
  })

  it('accepts pasted api-dock:diff JSON', async () => {
    const wrapper = mount(DiffView)

    await wrapper.get('[data-testid="diff-input"]').setValue(JSON.stringify({
      has_breaking: false,
      changes: [{
        severity: 'additive',
        path: '/health.get',
        operation: 'GET /health',
        type: 'operation_added',
        description: 'GET /health was added.',
      }],
    }))

    expect(wrapper.get('[data-severity="additive"]').text()).toContain('GET /health was added.')
  })
})
