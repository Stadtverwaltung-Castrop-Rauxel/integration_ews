
export type CorrelationType = 'CC' | 'EC' | 'TC';
export type CorrelationActions = 'C' | 'U' | 'D';

export interface Correlation {
	id: string | null
	roid: string | null
	loid: string | null
	type: CorrelationType
	action: CorrelationActions
}

export interface Collection {
	id: string
	name?: string
	count?: number
}
