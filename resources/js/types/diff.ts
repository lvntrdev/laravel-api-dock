export type DiffSeverity = 'breaking' | 'additive' | 'cosmetic'

export interface SpecDiffChange {
  severity: DiffSeverity
  path: string
  operation: string | null
  type: string
  description: string
}

export interface SpecDiffResult {
  has_breaking: boolean
  changes: SpecDiffChange[]
}
