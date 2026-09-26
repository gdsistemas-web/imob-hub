export type CreditStatus = 'draft' | 'submitted' | 'in_analysis' | 'pending_docs' | 'approved' | 'approved_conditions' | 'rejected' | 'cancelled';
export type Method = 'cash' | 'financing' | 'down_payment_financing' | 'rental';
export type Person = Partial<Record<'name' | 'cpf' | 'rg' | 'rg_issuer' | 'birth_date' | 'nationality' | 'marital_status' | 'marriage_regime' | 'profession' | 'employment_type' | 'employer' | 'employment_since' | 'monthly_income' | 'other_income' | 'phone' | 'email' | 'zip_code' | 'address' | 'city' | 'state' | 'relationship', string>>;
export type CreditDoc = {id: string; label: string; original_name: string; mime_type: string; size_bytes: number; created_at: string; uploaded_by_name: string};
export type CreditApp = {
  id: string; code: string; status: CreditStatus; payment_method: Method; business_type: 'purchase' | 'rental';
  offer_value: string | null; down_payment: string | null; fgts_value: string | null; financing_value: string | null; financing_term_months: number | null; bank_preference: string | null;
  rent_value: string | null; guarantee_type: string | null; lease_months: number | null; move_in_date: string | null;
  applicant_name: string; household_income: string | null; notes: string | null; consent_at: string | null;
  decision_notes: string | null; approved_value: string | null; approved_conditions: string | null; submitted_at: string | null; decided_at: string | null;
  applicant: Person; co_applicant: Person | null; guarantor: Person | null;
  property: {id: string; title: string; reference_code: string; property_type: string; price: string; rental_price: string | null; condo_fee: string | null; iptu_yearly: string | null; neighborhood: string | null; city: string | null; state: string | null};
  client: {id: string; name: string; email: string | null; phone: string | null; stage: string} | null;
  events: {action: string; notes: string | null; created_at: string; user_name: string}[];
  documents: CreditDoc[]; required_documents: string[]; missing: string[];
  broker_name: string; analyst_name: string | null; can_edit: boolean; can_decide: boolean; contract: {id: string; code: string; status: string} | null;
  labels: {methods: Record<Method, string>; documents: Record<string, string>};
};
export type CreditRow = Pick<CreditApp, 'id' | 'code' | 'status' | 'payment_method' | 'business_type' | 'offer_value' | 'rent_value' | 'financing_value' | 'approved_value' | 'applicant_name' | 'household_income' | 'submitted_at' | 'decided_at'> & {updated_at: string; property_title: string; property_reference: string; broker_name: string; analyst_name: string | null; analyst_id: string | null};

export const creditStatus: Record<CreditStatus, string> = {draft: 'Rascunho', submitted: 'Aguardando analista', in_analysis: 'Em análise', pending_docs: 'Pendência', approved: 'Aprovada', approved_conditions: 'Aprovada com condições', rejected: 'Reprovada', cancelled: 'Cancelada'};
export const methodLabel: Record<Method, string> = {cash: 'Venda à vista', financing: 'Venda financiada', down_payment_financing: 'Entrada + financiamento', rental: 'Locação'};
export const maritalLabel: Record<string, string> = {single: 'Solteiro(a)', married: 'Casado(a)', stable_union: 'União estável', divorced: 'Divorciado(a)', widowed: 'Viúvo(a)', separated: 'Separado(a)'};
export const regimeLabel: Record<string, string> = {partial: 'Comunhão parcial de bens', universal: 'Comunhão universal de bens', separation: 'Separação total de bens', final_participation: 'Participação final nos aquestos'};
export const employmentLabel: Record<string, string> = {clt: 'CLT', public: 'Servidor público', self: 'Autônomo / profissional liberal', owner: 'Empresário', retired: 'Aposentado / pensionista', other: 'Outro'};
export const guaranteeLabel: Record<string, string> = {deposit: 'Caução (depósito)', guarantor: 'Fiador', insurance: 'Seguro-fiança', capitalization: 'Título de capitalização', none: 'Sem garantia'};
export const eventText: Record<string, string> = {created: 'Ficha criada', submitted: 'Enviada para análise', resubmitted: 'Reenviada com pendências resolvidas', claimed: 'Assumida pelo analista', pending_docs: 'Pendência solicitada', approved: 'Aprovada', approved_conditions: 'Aprovada com condições', rejected: 'Reprovada', cancelled: 'Cancelada'};

export const formatCpf = (v?: string | null) => { const d = (v || '').replace(/\D/g, ''); return d.length === 11 ? `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6, 9)}-${d.slice(9)}` : v || '' };

/** Parcela pela Tabela Price com taxa anual efetiva. */
export function installment(principal: number, annualRate: number, months: number) {
  if (!principal || !months) return 0;
  const i = Math.pow(1 + annualRate / 100, 1 / 12) - 1;
  return i === 0 ? principal / months : principal * i / (1 - Math.pow(1 + i, -months));
}
