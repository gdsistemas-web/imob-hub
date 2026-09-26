<?php
declare(strict_types=1);

/**
 * Modelos-base de contrato e catálogo de variáveis.
 * São pontos de partida redigidos com as cláusulas usuais do mercado e DEVEM ser revisados pelo jurídico
 * da imobiliária. O administrador edita o texto em Configurações → Contratos; o texto editado fica no banco.
 *
 * Marcação: "# " título centralizado · "## " título de cláusula · "- " item de lista · linha em branco separa parágrafos
 * · {{variavel}} é substituída pelos dados da ficha, do imóvel e da imobiliária · {{assinaturas}} posiciona o bloco de assinaturas.
 */
final class ContractTemplates {
 public const KEYS = ['sale_cash'=>'Venda à vista', 'sale_financed'=>'Venda financiada', 'sale_down_payment'=>'Venda com entrada + financiamento', 'lease'=>'Locação'];
 public const BY_METHOD = ['cash'=>'sale_cash', 'financing'=>'sale_financed', 'down_payment_financing'=>'sale_down_payment', 'rental'=>'lease'];

 /** Variáveis disponíveis, agrupadas para a tela de edição. */
 public const VARIABLES = [
  'Contrato' => ['contrato.codigo'=>'Código do contrato', 'contrato.data'=>'Data por extenso', 'contrato.cidade'=>'Cidade de assinatura', 'contrato.foro'=>'Comarca do foro', 'condicoes_especiais'=>'Condições especiais (ficha + contrato)'],
  'Imobiliária' => ['imobiliaria.nome'=>'Nome fantasia', 'imobiliaria.qualificacao'=>'Razão social, CNPJ, CRECI e endereço', 'corretor.nome'=>'Corretor responsável', 'corretor.creci'=>'CRECI do corretor'],
  'Partes' => ['comprador.qualificacao'=>'Comprador(es) qualificado(s)', 'comprador.nome'=>'Nome do comprador', 'vendedor.qualificacao'=>'Vendedor qualificado', 'vendedor.nome'=>'Nome do vendedor', 'locatario.qualificacao'=>'Locatário qualificado', 'locatario.nome'=>'Nome do locatário', 'locador.qualificacao'=>'Locador qualificado', 'locador.nome'=>'Nome do locador', 'fiador.qualificacao'=>'Fiador qualificado'],
  'Imóvel' => ['imovel.descricao'=>'Tipo, áreas e cômodos', 'imovel.endereco'=>'Endereço completo', 'imovel.matricula'=>'Matrícula', 'imovel.cartorio'=>'Cartório de registro', 'imovel.iptu_inscricao'=>'Inscrição do IPTU', 'imovel.condominio'=>'Condomínio mensal'],
  'Venda' => ['venda.preco'=>'Preço (R$ e extenso)', 'venda.sinal'=>'Sinal/arras (R$ e extenso)', 'venda.sinal_data'=>'Data do sinal', 'venda.entrada'=>'Entrada (R$ e extenso)', 'venda.fgts'=>'Valor do FGTS', 'venda.financiado'=>'Valor financiado (R$ e extenso)', 'venda.banco'=>'Instituição financeira', 'venda.prazo_financiamento'=>'Prazo do financiamento', 'venda.saldo'=>'Saldo à vista (R$ e extenso)', 'venda.saldo_data'=>'Data do saldo', 'venda.prazo_escritura'=>'Prazo para escritura (dias)', 'venda.posse'=>'Entrega da posse', 'venda.multa'=>'Multa por descumprimento (%)'],
  'Comissão' => ['comissao.percentual'=>'Percentual', 'comissao.valor'=>'Valor (R$ e extenso)', 'comissao.responsavel'=>'Quem paga'],
  'Locação' => ['locacao.aluguel'=>'Aluguel (R$ e extenso)', 'locacao.prazo_meses'=>'Prazo em meses', 'locacao.inicio'=>'Início', 'locacao.fim'=>'Término', 'locacao.vencimento_dia'=>'Dia do vencimento', 'locacao.indice'=>'Índice de reajuste', 'locacao.garantia_texto'=>'Cláusula da garantia', 'locacao.multa_alugueis'=>'Multa rescisória (aluguéis)', 'locacao.finalidade'=>'Residencial ou não residencial'],
 ];

 public static function defaults(): array {
  $common_sale_end = <<<'TXT'
## CLÁUSULA — DA DOCUMENTAÇÃO E DAS DESPESAS
O VENDEDOR se obriga a apresentar, em até 15 (quinze) dias da assinatura deste instrumento, as certidões negativas pessoais e do imóvel necessárias à lavratura da escritura, bem como a quitação de condomínio e IPTU até a data da entrega da posse.

Correrão por conta do COMPRADOR as despesas de escritura, ITBI, registro e, se houver, as tarifas da instituição financeira. Correrão por conta do VENDEDOR os débitos de qualquer natureza que recaiam sobre o imóvel até a data da entrega da posse.

## CLÁUSULA — DA POSSE
A posse do imóvel será transmitida ao COMPRADOR {{venda.posse}}, livre de pessoas e coisas, mediante entrega das chaves e termo de vistoria.

## CLÁUSULA — DA INTERMEDIAÇÃO
As partes reconhecem que o presente negócio foi intermediado por {{imobiliaria.qualificacao}}, por meio do corretor {{corretor.nome}} (CRECI {{corretor.creci}}), a quem é devida a comissão de {{comissao.percentual}} sobre o preço, correspondente a {{comissao.valor}}, a ser paga pelo {{comissao.responsavel}} na data do recebimento do sinal, nos termos dos arts. 722 a 729 do Código Civil.

## CLÁUSULA — DO DESCUMPRIMENTO
A parte que der causa à rescisão deste contrato pagará à outra multa equivalente a {{venda.multa}} do preço total, sem prejuízo das perdas e danos comprovadas, observado o disposto nos arts. 417 a 420 do Código Civil quanto às arras.

## CLÁUSULA — DAS CONDIÇÕES ESPECIAIS
{{condicoes_especiais}}

## CLÁUSULA — DA IRREVOGABILIDADE, DA ASSINATURA ELETRÔNICA E DO FORO
Este contrato é celebrado em caráter irrevogável e irretratável, obrigando as partes, seus herdeiros e sucessores. As partes reconhecem a validade da assinatura eletrônica deste instrumento, nos termos do art. 10, § 2º, da MP 2.200-2/2001 e da Lei 14.063/2020, dispensada a assinatura de testemunhas conforme o art. 784, § 4º, do Código de Processo Civil.

Fica eleito o foro da Comarca de {{contrato.foro}} para dirimir quaisquer questões oriundas deste contrato.

{{assinaturas}}
TXT;

  $parties_sale = <<<'TXT'
Pelo presente instrumento particular, de um lado, como VENDEDOR: {{vendedor.qualificacao}}; e, de outro lado, como COMPRADOR: {{comprador.qualificacao}}; e, como INTERVENIENTE INTERMEDIADORA: {{imobiliaria.qualificacao}}; têm entre si justo e contratado o seguinte:

## CLÁUSULA — DO OBJETO
O VENDEDOR é legítimo proprietário e possuidor do imóvel: {{imovel.descricao}}, situado em {{imovel.endereco}}, objeto da matrícula nº {{imovel.matricula}} do {{imovel.cartorio}}, inscrição municipal (IPTU) nº {{imovel.iptu_inscricao}}, livre e desembaraçado de quaisquer ônus, dívidas ou ações, que por este instrumento promete vender ao COMPRADOR.
TXT;

  return [
   'sale_cash' => "# INSTRUMENTO PARTICULAR DE COMPROMISSO DE COMPRA E VENDA DE IMÓVEL\n\nContrato nº {{contrato.codigo}}\n\n".$parties_sale.<<<'TXT'


## CLÁUSULA — DO PREÇO E DA FORMA DE PAGAMENTO
O preço certo e ajustado da venda é de {{venda.preco}}, que o COMPRADOR pagará da seguinte forma:

- a título de sinal e princípio de pagamento (arras confirmatórias), {{venda.sinal}}, em {{venda.sinal_data}};
- o saldo de {{venda.saldo}}, à vista, em {{venda.saldo_data}}, no ato da lavratura da escritura pública.

Os pagamentos serão feitos por transferência bancária para conta de titularidade do VENDEDOR, servindo o comprovante como recibo.

## CLÁUSULA — DA ESCRITURA
A escritura pública definitiva será lavrada em até {{venda.prazo_escritura}} dias contados desta data, em tabelionato de escolha do COMPRADOR, mediante a quitação integral do preço.

TXT."\n".$common_sale_end,

   'sale_financed' => "# INSTRUMENTO PARTICULAR DE COMPROMISSO DE COMPRA E VENDA DE IMÓVEL COM FINANCIAMENTO BANCÁRIO\n\nContrato nº {{contrato.codigo}}\n\n".$parties_sale.<<<'TXT'


## CLÁUSULA — DO PREÇO E DA FORMA DE PAGAMENTO
O preço certo e ajustado da venda é de {{venda.preco}}, que o COMPRADOR pagará da seguinte forma:

- a título de sinal e princípio de pagamento (arras confirmatórias), {{venda.sinal}}, em {{venda.sinal_data}};
- com recursos do FGTS, {{venda.fgts}}, se houver, liberados diretamente pela instituição financeira;
- o saldo de {{venda.financiado}}, por meio de financiamento imobiliário junto a {{venda.banco}}, pelo prazo de {{venda.prazo_financiamento}}, pago diretamente ao VENDEDOR pela instituição financeira após o registro do contrato de financiamento.

## CLÁUSULA — DA CONDIÇÃO DO FINANCIAMENTO
Este compromisso fica condicionado à aprovação definitiva do financiamento pela instituição financeira. O COMPRADOR se obriga a entregar a documentação exigida em até 10 (dez) dias e o VENDEDOR a fornecer a documentação do imóvel e a aceitar a avaliação do banco.

Caso o financiamento seja negado por motivo alheio à vontade das partes, este contrato será resolvido sem multa, devolvendo o VENDEDOR ao COMPRADOR os valores recebidos, corrigidos, em até 10 (dez) dias. Caso a negativa decorra de culpa de qualquer das partes, aplicar-se-á a cláusula de descumprimento.

## CLÁUSULA — DO CONTRATO DEFINITIVO
O contrato de compra e venda com financiamento emitido pela instituição financeira, com força de escritura pública (art. 61 da Lei 4.380/1964 e Lei 9.514/1997), substituirá a escritura, devendo ser assinado em até {{venda.prazo_escritura}} dias após a aprovação do crédito.

TXT."\n".$common_sale_end,

   'sale_down_payment' => "# INSTRUMENTO PARTICULAR DE COMPROMISSO DE COMPRA E VENDA DE IMÓVEL COM ENTRADA E FINANCIAMENTO\n\nContrato nº {{contrato.codigo}}\n\n".$parties_sale.<<<'TXT'


## CLÁUSULA — DO PREÇO E DA FORMA DE PAGAMENTO
O preço certo e ajustado da venda é de {{venda.preco}}, que o COMPRADOR pagará da seguinte forma:

- a título de sinal e princípio de pagamento (arras confirmatórias), {{venda.sinal}}, em {{venda.sinal_data}};
- a título de entrada, com recursos próprios, {{venda.entrada}}, no ato da assinatura do contrato de financiamento, abatido o sinal já pago;
- com recursos do FGTS, {{venda.fgts}}, se houver, liberados diretamente pela instituição financeira;
- o saldo de {{venda.financiado}}, por meio de financiamento imobiliário junto a {{venda.banco}}, pelo prazo de {{venda.prazo_financiamento}}, pago diretamente ao VENDEDOR pela instituição financeira após o registro do contrato de financiamento.

## CLÁUSULA — DA CONDIÇÃO DO FINANCIAMENTO
Este compromisso fica condicionado à aprovação definitiva do financiamento pela instituição financeira. O COMPRADOR se obriga a entregar a documentação exigida em até 10 (dez) dias e o VENDEDOR a fornecer a documentação do imóvel e a aceitar a avaliação do banco.

Caso o financiamento seja negado por motivo alheio à vontade das partes, este contrato será resolvido sem multa, devolvendo o VENDEDOR ao COMPRADOR os valores recebidos, corrigidos, em até 10 (dez) dias. Se a instituição aprovar valor inferior ao previsto, o COMPRADOR poderá complementar a diferença com recursos próprios, mantendo-se o negócio.

## CLÁUSULA — DO CONTRATO DEFINITIVO
O contrato de compra e venda com financiamento emitido pela instituição financeira, com força de escritura pública, substituirá a escritura, devendo ser assinado em até {{venda.prazo_escritura}} dias após a aprovação do crédito.

TXT."\n".$common_sale_end,

   'lease' => <<<'TXT'
# CONTRATO DE LOCAÇÃO DE IMÓVEL

Contrato nº {{contrato.codigo}}

Pelo presente instrumento particular, de um lado, como LOCADOR: {{locador.qualificacao}}; de outro lado, como LOCATÁRIO: {{locatario.qualificacao}}; e, como INTERVENIENTE INTERMEDIADORA: {{imobiliaria.qualificacao}}; têm entre si justo e contratado a presente locação, regida pela Lei 8.245/1991, mediante as cláusulas seguintes:

## CLÁUSULA — DO OBJETO E DA FINALIDADE
O objeto desta locação é o imóvel {{imovel.descricao}}, situado em {{imovel.endereco}}, destinado exclusivamente a uso {{locacao.finalidade}}, sendo vedada a sublocação, cessão ou empréstimo, total ou parcial, sem consentimento prévio e por escrito do LOCADOR.

## CLÁUSULA — DO PRAZO
A locação vigorará pelo prazo de {{locacao.prazo_meses}} meses, com início em {{locacao.inicio}} e término em {{locacao.fim}}, data em que o LOCATÁRIO se obriga a restituir o imóvel nas condições da vistoria inicial, salvo prorrogação nos termos da lei.

## CLÁUSULA — DO ALUGUEL E DOS ENCARGOS
O aluguel mensal é de {{locacao.aluguel}}, vencendo-se todo dia {{locacao.vencimento_dia}} do mês seguinte ao vencido, pago por boleto ou transferência indicados pela intermediadora.

Além do aluguel, são de responsabilidade do LOCATÁRIO as despesas ordinárias de condomínio (atualmente {{imovel.condominio}}), o IPTU, as contas de consumo (água, luz, gás, internet) e o seguro contra incêndio do imóvel, nos termos do art. 23 da Lei 8.245/1991. As despesas extraordinárias de condomínio são de responsabilidade do LOCADOR.

O atraso no pagamento sujeitará o LOCATÁRIO a multa de 10% (dez por cento), juros de 1% (um por cento) ao mês e correção monetária.

## CLÁUSULA — DO REAJUSTE
O aluguel será reajustado a cada 12 (doze) meses pela variação acumulada do {{locacao.indice}}, ou por outro índice oficial que o substitua.

## CLÁUSULA — DA GARANTIA
{{locacao.garantia_texto}}

## CLÁUSULA — DA CONSERVAÇÃO, DAS BENFEITORIAS E DA VISTORIA
O LOCATÁRIO declara receber o imóvel em perfeito estado, conforme laudo de vistoria que integra este contrato, obrigando-se a conservá-lo e a devolvê-lo no mesmo estado, ressalvado o desgaste natural pelo uso normal. Benfeitorias dependem de autorização escrita do LOCADOR e não serão indenizadas nem darão direito de retenção, salvo as necessárias.

## CLÁUSULA — DA RESCISÃO
A devolução antecipada do imóvel pelo LOCATÁRIO sujeitá-lo-á ao pagamento de multa equivalente a {{locacao.multa_alugueis}} aluguéis vigentes, reduzida proporcionalmente ao tempo de contrato cumprido (art. 4º da Lei 8.245/1991), dispensada nas hipóteses do parágrafo único do mesmo artigo. A infração de qualquer cláusula autoriza a rescisão do contrato.

## CLÁUSULA — DA INTERMEDIAÇÃO
A locação foi intermediada por {{imobiliaria.qualificacao}}, por meio do corretor {{corretor.nome}} (CRECI {{corretor.creci}}), cabendo ao LOCADOR a remuneração pela intermediação, conforme contrato próprio de administração.

## CLÁUSULA — DAS CONDIÇÕES ESPECIAIS
{{condicoes_especiais}}

## CLÁUSULA — DA ASSINATURA ELETRÔNICA E DO FORO
As partes reconhecem a validade da assinatura eletrônica deste instrumento, nos termos do art. 10, § 2º, da MP 2.200-2/2001 e da Lei 14.063/2020, dispensada a assinatura de testemunhas conforme o art. 784, § 4º, do Código de Processo Civil. Fica eleito o foro da Comarca de {{contrato.foro}}.

{{assinaturas}}
TXT,
  ];
 }

 /** Valor monetário por extenso em português ("mil e duzentos reais e cinquenta centavos"). */
 public static function moneyWords(float $value): string {
  $reais = (int)floor($value + 1e-6); $cents = (int)round(($value - $reais) * 100);
  $parts = [];
  if ($reais > 0) $parts[] = self::words($reais).(($reais >= 1000000 && $reais % 1000000 === 0) ? ' de' : '').($reais === 1 ? ' real' : ' reais');
  if ($cents > 0) $parts[] = self::words($cents).($cents === 1 ? ' centavo' : ' centavos');
  return $parts ? implode(' e ', $parts) : 'zero real';
 }

 private static function words(int $n): string {
  $u = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez', 'onze', 'doze', 'treze', 'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];
  $t = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
  $h = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];
  $upTo999 = function (int $n) use ($u, $t, $h): string {
   if ($n === 100) return 'cem';
   $out = [];
   if ($n >= 100) { $out[] = $h[intdiv($n, 100)]; $n %= 100; }
   if ($n >= 20) { $out[] = $t[intdiv($n, 10)]; $n %= 10; }
   if ($n > 0) $out[] = $u[$n];
   return implode(' e ', $out);
  };
  $groups = [[1000000000, 'bilhão', 'bilhões'], [1000000, 'milhão', 'milhões'], [1000, 'mil', 'mil'], [1, '', '']];
  $out = '';
  foreach ($groups as [$size, $one, $many]) {
   if ($n < $size) continue;
   $q = intdiv($n, $size); $n %= $size;
   $text = trim(($size === 1000 && $q === 1 ? '' : $upTo999($q)).' '.($q === 1 ? $one : $many));
   // "e" antes de grupo menor que cem ou de centena exata: "um milhão e quinhentos mil", "mil e cem"
   $out .= $out === '' ? $text : (($q < 100 || $q % 100 === 0) ? ' e ' : ' ').$text;
  }
  return $out === '' ? 'zero' : $out;
 }
}
