export type Photo = {id: string; url: string; is_cover: boolean; category: string; slot: string; caption?: string | null; width?: number | null; height?: number | null};
export type GuideSlot = {slot: string; category: string; label: string; hint: string};
export type Guide = {group: string; required: GuideSlot[]; optional: {category: string; label: string}[]};
export type PropertyType = {value: string; group: string; condo: boolean};
export type Property = {
  id: string; reference_code: string; title: string; property_type: string; purpose: 'purchase' | 'rental' | 'both';
  status: string; listing_status: ListingStatus; price?: string | null; rental_price?: string | null; condo_fee?: string | null; iptu_yearly?: string | null;
  address?: string; region?: string; zip_code?: string; street?: string; street_number?: string; complement?: string; neighborhood?: string; city?: string; state?: string; condo_name?: string; show_full_address?: number | string;
  area_total?: string | null; area_built?: string | null; bedrooms?: number | null; suites?: number | null; bathrooms?: number | null; parking_spaces?: number | null; floor_number?: number | null; year_built?: number | null;
  furnished?: string | null; accepts_pets?: number | null; accepts_financing?: number | null; accepts_exchange?: number | null; features: string[]; condo_features: string[]; description?: string | null;
  owner_name?: string; owner_document?: string; owner_phone?: string; owner_email?: string; registry_number?: string; registry_office?: string; iptu_number?: string; keys_location?: string;
  exclusive_listing?: number | string; commission_percent?: string | null; available_from?: string | null;
  created_by?: string | null; creator_name?: string | null; review_notes?: string | null; reviewed_at?: string | null; submitted_at?: string | null; updated_at?: string;
  photos: Photo[]; photo_count: number; cover_url: string | null;
  guide?: Guide; missing?: string[]; can_edit?: boolean; can_review?: boolean; events?: {action: string; notes?: string; created_at: string; user_name: string}[];
};
export type ListingStatus = 'draft' | 'pending_review' | 'approved' | 'rejected' | 'archived';

export const listingLabel: Record<ListingStatus, string> = {draft: 'Rascunho', pending_review: 'Aguardando aprovação', approved: 'Publicado', rejected: 'Reprovado', archived: 'Arquivado'};
export const commercialLabel: Record<string, string> = {available: 'Disponível', reserved: 'Reservado', sold: 'Vendido', rented: 'Alugado', inactive: 'Inativo'};
export const purposeLabel: Record<string, string> = {purchase: 'Venda', rental: 'Locação', both: 'Venda e locação'};
export const eventLabel: Record<string, string> = {created: 'Cadastro criado', submitted: 'Enviado para aprovação', approved: 'Aprovado', rejected: 'Reprovado', archived: 'Arquivado', edited_after_approval: 'Alterado após aprovação'};

export const FEATURES = ['Armários planejados', 'Ar-condicionado', 'Varanda', 'Varanda gourmet', 'Churrasqueira', 'Piscina', 'Quintal', 'Jardim', 'Escritório', 'Closet', 'Lavabo', 'Despensa', 'Aquecimento a gás', 'Aquecimento solar', 'Energia solar', 'Portão eletrônico', 'Vista livre', 'Sol da manhã', 'Reformado', 'Mobiliado'];
export const CONDO_FEATURES = ['Portaria 24h', 'Elevador', 'Piscina', 'Academia', 'Salão de festas', 'Espaço gourmet', 'Playground', 'Quadra', 'Brinquedoteca', 'Pet place', 'Coworking', 'Bicicletário', 'Lavanderia coletiva', 'Gerador', 'Vaga de visitante'];

/** Reduz a foto no aparelho antes do envio (economiza dados móveis) e respeita a orientação da câmera. */
export async function compressPhoto(file: File, maxSide = 2048): Promise<{blob: Blob; width: number; height: number}> {
  const bitmap = await createImageBitmap(file, {imageOrientation: 'from-image'} as ImageBitmapOptions);
  const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
  const width = Math.round(bitmap.width * scale), height = Math.round(bitmap.height * scale);
  const canvas = document.createElement('canvas');
  canvas.width = width; canvas.height = height;
  canvas.getContext('2d')!.drawImage(bitmap, 0, 0, width, height);
  bitmap.close();
  const blob = await new Promise<Blob>((ok, fail) => canvas.toBlob(b => b ? ok(b) : fail(new Error('Não foi possível processar a foto.')), 'image/jpeg', 0.86));
  return {blob, width, height};
}

export function suggestTitle(p: Partial<Property>): string {
  const parts = [p.property_type || 'Imóvel'];
  if (p.bedrooms) parts.push(`${p.bedrooms} quarto${p.bedrooms > 1 ? 's' : ''}`);
  if (p.area_built || p.area_total) parts.push(`${Math.round(Number(p.area_built || p.area_total))} m²`);
  return parts.join(', ') + (p.neighborhood ? ` em ${p.neighborhood}` : '') + (p.purpose === 'rental' ? ' para alugar' : p.purpose === 'purchase' ? ' à venda' : '');
}
