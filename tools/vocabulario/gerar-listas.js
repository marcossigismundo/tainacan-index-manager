// Gera as quatro listas do "Vocabulário da busca" a partir do vocabulário real do acervo.
//
// Uso: node gerar-listas.js <pasta-de-trabalho> <pasta-de-saída>
// A pasta de trabalho precisa ter:
//   vocab.json    saída de vocab-dump.php (só o JSON, depois do marcador JSONSTART)
//   br-utf8.txt   dicionário IME-USP: https://www.ime.usp.br/~pf/dicios/br-utf8.txt
//   palavras.txt  https://raw.githubusercontent.com/pythonprobr/palavras/master/palavras.txt
//   en.txt        https://raw.githubusercontent.com/dwyl/english-words/master/words_alpha.txt
// Depois, confira com: php validar-listas.php <pasta-de-saída>
const fs = require('fs'), path = require('path');
const [S, OUT] = process.argv.slice(2);
const fold = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const data = JSON.parse(fs.readFileSync(path.join(S, 'vocab.json'), 'utf8'));
const N = data.items;

const V = new Map();
for (const [k, tf, df, cap, best] of data.vocab) V.set(k, { tf, df, cap, best });
const pt = new Set();
const proper = new Set(); // nomes próprios do dicionário (Villa, Penna…): nunca viram "grafia antiga de palavra comum"
for (const f of ['br-utf8.txt', 'palavras.txt']) for (const w of fs.readFileSync(path.join(S, f), 'utf8').split(/\r?\n/)) {
	if (!w) continue;
	if (/^[A-ZÀ-Ý]/.test(w)) proper.add(fold(w)); else pt.add(fold(w));
}
const en = new Set(fs.readFileSync(path.join(S, 'en.txt'), 'utf8').split(/\r?\n/).map(w => w.trim().toLowerCase()).filter(Boolean));
const best = k => (V.get(k) || {}).best || k;
const df = k => (V.get(k) || {}).df || 0;
const singular = w => w.replace(/s$/, '');
const fmt = n => n.toLocaleString('pt-BR');
const today = '24/09/2026';

function damerau1(a, b) {
	if (a === b) return false;
	const la = a.length, lb = b.length;
	if (Math.abs(la - lb) > 1) return false;
	if (la === lb) {
		const diff = [];
		for (let i = 0; i < la; i++) if (a[i] !== b[i]) diff.push(i);
		if (diff.length === 1) return true;
		return diff.length === 2 && diff[1] === diff[0] + 1 && a[diff[0]] === b[diff[1]] && a[diff[1]] === b[diff[0]];
	}
	const [s, l] = la < lb ? [a, b] : [b, a];
	let i = 0, j = 0, skip = 0;
	while (i < s.length && j < l.length) { if (s[i] === l[j]) { i++; j++; } else { if (++skip > 1) return false; j++; } }
	return true;
}
const deletes = w => { const o = [w]; for (let i = 0; i < w.length; i++) o.push(w.slice(0, i) + w.slice(i + 1)); return o; };

const report = {};

/* =============================== 4. Palavras ignoradas =============================== */
const techStop = ['https', 'http', 'www', 'gov', 'br', 'org', 'html', 'php', 'jpg', 'jpeg', 'png', 'pdf'];
const stopActive = techStop.filter(w => df(w) / N >= 0.01).map(w => [w, df(w)]);
report.stopwords = stopActive.length;
{
	const L = [];
	L.push('# Palavras ignoradas pela busca — brasiliana3');
	L.push(`# Gerado em ${today} a partir dos ${fmt(N)} itens publicados.`);
	L.push('# Uma palavra por linha. Linhas que começam com # são comentários e não têm efeito.');
	L.push('#');
	L.push('# Estas palavras são pedaços de endereços de internet (https://acervos.museus.gov.br/...)');
	L.push('# que estão guardados como texto em quase todos os itens. Ninguém as busca, e elas');
	L.push('# fazem a busca por "br" ou "gov" devolver o acervo inteiro.');
	L.push('#');
	for (const [w, d] of stopActive) { L.push(`# ${w}: em ${fmt(d)} itens (${(100 * d / N).toFixed(1)}%)`); L.push(w); }
	L.push('');
	L.push('# ---------------------------------------------------------------------------');
	L.push('# NÃO recomendadas (deixadas como comentário de propósito):');
	L.push('# são muito frequentes, mas quem buscar só uma delas deixaria de encontrar qualquer item.');
	for (const w of ['museu', 'acervo', 'acervos', 'museus', 'museologico', 'historico', 'nacional']) {
		if (df(w)) L.push(`#   ${best(w)} — em ${(100 * df(w) / N).toFixed(0)}% dos itens`);
	}
	L.push('# O texto padrão de direitos ("reprodução proibida para fins comerciais...") também aparece');
	L.push('# em 9% a 20% dos itens. O certo é deixar de indexar esse campo como texto, não ignorar as palavras.');
	fs.writeFileSync(path.join(OUT, '4-palavras-ignoradas.txt'), L.join('\n') + '\n');
}

/* =============================== 2. Grafias e variantes =============================== */
const rules = [
	[/ph/g, 'f', 'safe'], [/th/g, 't', 'safe'], [/y/g, 'i', 'safe'], [/mm/g, 'm', 'safe'], [/nn/g, 'n', 'safe'],
	[/ll/g, 'l', 'safe'], [/tt/g, 't', 'safe'], [/pp/g, 'p', 'safe'], [/ff/g, 'f', 'safe'], [/bb/g, 'b', 'safe'],
	[/dd/g, 'd', 'safe'], [/gg/g, 'g', 'safe'], [/^sc(?=[ei])/, 'c', 'safe'], [/chr/g, 'cr', 'safe'],
	[/cc(?=[aeiou])/g, 'c', 'risky'], [/ct/g, 't', 'risky'], [/pt/g, 't', 'risky'], [/(?<=[aeiou])z(?=[aeiou])/g, 's', 'risky'],
	[/(?<=[aeiou])s(?=[aeiou])/g, 'z', 'risky'],
];
const modernize = (w, which) => {
	let m = w;
	for (const [re, to, kind] of rules) if (which === 'all' || kind === which) m = m.replace(re, to);
	return m;
};
const groups = new Map();   // modern key -> {modern, archaic:Set, hits, kind}
const reviewNames = [];
const variantWords = new Set();
for (const [k, e] of V) {
	if (k.length < 4 || e.tf < 2) continue;
	for (const which of ['safe', 'all']) {
		const m = modernize(k, which);
		if (m === k) continue;
		const me = V.get(m);
		const archaicInDict = pt.has(k), modernInDict = pt.has(m);
		const bothNames = me && e.cap >= 0.7 && me.cap >= 0.7 && me.df >= 2 && e.df >= 2 && !archaicInDict !== undefined;
		let kind = null;
		if (!archaicInDict && modernInDict && !en.has(k) && k.length >= 5 && m.length >= 5) {
			if (proper.has(k)) {
				// Nome próprio cuja forma atual é palavra comum (Villa × vila, Penna × pena): só revisão.
				if (me) { reviewNames.push([best(k), e.df, best(m), me.df]); }
				break;
			}
			// Regras arriscadas (ct→t, pt→t, cc→c, z↔s) só valem com a forma atual claramente dominante:
			// evita captador → catador, que são palavras diferentes.
			if (which === 'all' && !(me && me.df >= 3 * e.df)) continue;
			// Forma atual ausente do acervo: só nas trocas clássicas (ph, th, y, dobradas) e palavras longas.
			if (!me && (which !== 'safe' || k.length < 6)) continue;
			kind = 'word';
		} else if (me && e.cap >= 0.7 && me.cap >= 0.7 && me.df >= 2 && e.df >= 2 && !(en.has(k) && en.has(m))
			&& (Math.min(k.length, m.length) >= 4 || (k.length - m.length === 1 && m.length >= 3))) {
			// Nomes próprios nas duas formas: Souza/Sousa, Mello/Melo, Arthur/Artur.
			kind = 'name';
		}
		if (!kind) continue;
		const key = (kind === 'word' ? singular(m) : m) + '|' + kind;
		if (!groups.has(key)) groups.set(key, { modern: kind === 'word' && V.has(singular(m)) ? singular(m) : m, archaic: new Set(), hits: 0, kind, modernDf: me ? me.df : 0 });
		const g = groups.get(key);
		const a = kind === 'word' && V.has(singular(k)) && singular(k) !== k ? singular(k) : k;
		if (!g.archaic.has(a)) { g.archaic.add(a); g.hits += e.df; }
		variantWords.add(k);
		break;
	}
}
// Um grupo "nome" cujo arcaico também é palavra comum do dicionário e muito mais frequente fica para revisão.
const words = [], names = [];
for (const g of groups.values()) {
	const archaic = [...g.archaic].filter(a => a !== g.modern);
	if (!archaic.length) continue;
	const line = [best(g.modern), ...archaic.map(best)].join(', ');
	(g.kind === 'word' ? words : names).push([line, g.hits, g.modernDf]);
}
words.sort((a, b) => b[1] - a[1]); names.sort((a, b) => b[1] - a[1]);
reviewNames.sort((a, b) => b[1] - a[1]);

/* Taxonomias: quase-duplicatas que diferem numa palavra só. */
const taxTypos = new Map();     // correta -> Set(erradas)   (vai para correções)
const taxNamePairs = [];        // pares de nomes para revisão (variantes comentadas)
for (const [tax, rows] of Object.entries(data.tax)) {
	const terms = rows.map(([name, count]) => [name, +count, fold(name).replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim()]).filter(t => t[2].length >= 5);
	const idx = new Map();
	terms.forEach((t, i) => { for (const d of deletes(t[2])) { if (!idx.has(d)) idx.set(d, []); idx.get(d).push(i); } });
	const seen = new Set();
	terms.forEach((t, i) => {
		for (const d of deletes(t[2])) for (const j of idx.get(d) || []) {
			if (j <= i) continue;
			const u = terms[j]; const pk = i + ':' + j;
			if (seen.has(pk) || !damerau1(t[2], u[2])) continue;
			seen.add(pk);
			const ta = t[2].split(' '), tb = u[2].split(' ');
			if (ta.length !== tb.length) continue;
			const pos = ta.map((w, x) => w !== tb[x] ? x : -1).filter(x => x >= 0);
			if (pos.length !== 1) continue;
			const wa = ta[pos[0]], wb = tb[pos[0]];
			if (singular(wa) === singular(wb) || wa.length < 4) continue;
			if (pt.has(wa) !== pt.has(wb)) {
				const [good, bad] = pt.has(wa) ? [wa, wb] : [wb, wa];
				if (en.has(bad)) continue;
				if (!taxTypos.has(good)) taxTypos.set(good, new Set());
				taxTypos.get(good).add(bad);
			} else if (!pt.has(wa) && !pt.has(wb) && tax === 'Autoria' && ta.length >= 2) {
				taxNamePairs.push([t[0], t[1], u[0], u[1]]);
			}
		}
	});
}
taxNamePairs.sort((a, b) => (b[1] + b[3]) - (a[1] + a[3]));
report.variants = { words: words.length, names: names.length, review_names: reviewNames.length, autoria_review: taxNamePairs.length };
{
	const L = [];
	L.push('# Grafias antigas e variantes — brasiliana3');
	L.push(`# Gerado em ${today} a partir dos ${fmt(N)} itens publicados. Uma equivalência por linha.`);
	L.push('# A primeira forma é a atual; as seguintes são as grafias encontradas nos registros.');
	L.push('# Quem buscar qualquer uma encontra os itens escritos com as outras.');
	L.push('# Linhas que começam com # são comentários e não têm efeito: para ativar uma, apague o #.');
	L.push('');
	L.push(`# === 1. Ortografia antiga de palavras comuns (${words.length} regras) ===`);
	L.push('# A forma antiga não está no dicionário e a forma atual está. Ex.: thesouro → tesouro.');
	for (const [l] of words) L.push(l);
	L.push('');
	L.push(`# === 2. Nomes próprios escritos de duas formas no acervo (${names.length} regras) ===`);
	L.push('# As duas formas aparecem como nome (com inicial maiúscula) em 2 ou mais itens. Ex.: Souza/Sousa.');
	for (const [l] of names) L.push(l);
	L.push('');
	L.push(`# === 3. PARA REVISAR: nome antigo que é palavra comum na grafia atual (${reviewNames.length}) ===`);
	L.push('# Ex.: "Villa" (Villa-Lobos) × "vila". Ativar faria a busca por "vila" trazer o compositor.');
	for (const [a, da, m, dm] of reviewNames.slice(0, 80)) L.push(`# ${m}, ${a}        (${fmt(da)} itens com "${a}", ${fmt(dm)} com "${m}")`);
	L.push('');
	L.push(`# === 4. PARA REVISAR: autorias quase iguais na taxonomia Autoria (${taxNamePairs.length}) ===`);
	L.push('# Diferem numa letra. Podem ser a mesma pessoa (melhor: fundir os termos no Tainacan) ou pessoas diferentes.');
	for (const [a, ca, b, cb] of taxNamePairs) L.push(`# ${fold(a).replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim()}, ${fold(b).replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim()}        (${ca} × ${cb} itens: "${a}" / "${b}")`);
	fs.writeFileSync(path.join(OUT, '2-grafias-e-variantes.txt'), L.join('\n') + '\n');
}

/* =============================== 3. Correções =============================== */
const freqIdx = new Map();
for (const [k, e] of V) {
	if (e.tf < 30 || k.length < 5 || !pt.has(k)) continue;
	for (const d of deletes(k)) { if (!freqIdx.has(d)) freqIdx.set(d, []); freqIdx.get(d).push(k); }
}
const typos = new Map(); // correta -> [[errada, tf]]
let typoHits = 0;
for (const [k, e] of V) {
	if (e.tf > 3 || k.length < 5 || e.cap >= 0.5 || pt.has(k) || en.has(k) || variantWords.has(k)) continue;
	let bestC = null;
	for (const d of deletes(k)) for (const c of freqIdx.get(d) || []) {
		if (c === k || c[0] !== k[0] || singular(c) === singular(k) || !damerau1(c, k)) continue;
		if (V.get(c).tf < 20 * e.tf) continue;
		if (!bestC || V.get(c).tf > V.get(bestC).tf) bestC = c;
	}
	// transposições não aparecem no índice de deleções: tenta trocar pares vizinhos
	if (!bestC) for (let i = 0; i < k.length - 1; i++) {
		const c = k.slice(0, i) + k[i + 1] + k[i] + k.slice(i + 2);
		if (V.has(c) && pt.has(c) && V.get(c).tf >= Math.max(30, 20 * e.tf) && c[0] === k[0]) { bestC = c; break; }
	}
	if (!bestC) continue;
	if (!typos.has(bestC)) typos.set(bestC, []);
	typos.get(bestC).push([k, e.tf]);
	typoHits += e.df;
}
for (const [good, bads] of taxTypos) {
	if (!typos.has(good)) typos.set(good, []);
	for (const b of bads) if (!typos.get(good).some(x => x[0] === b)) typos.get(good).push([b, df(b)]);
}
const typoLines = [...typos.entries()].map(([g, bs]) => [best(g), bs.map(b => b[0]), V.has(g) ? V.get(g).tf : 0]).sort((a, b) => b[2] - a[2]);
const typoForms = typoLines.reduce((s, l) => s + l[1].length, 0);

const publicTypos = [
	['exceção', ['excessão', 'exeção', 'excessao']], ['privilégio', ['previlégio', 'privilegio']], ['beneficente', ['beneficiente']],
	['empecilho', ['impecilho']], ['cabeleireiro', ['cabeleleiro', 'cabelereiro']], ['asterisco', ['asterístico']],
	['mortadela', ['mortandela']], ['adivinhar', ['advinhar']], ['bicarbonato', ['bicabornato']], ['meteorologia', ['meterologia']],
	['mendigo', ['mendingo']], ['prazeroso', ['prazeiroso']], ['reivindicação', ['reinvindicação']], ['frustrado', ['frustado']],
	['lagartixa', ['largatixa']], ['travesseiro', ['trabisseiro', 'travisseiro']], ['bandeja', ['bandeija']], ['caranguejo', ['carangueijo']],
	['salsicha', ['salchicha']], ['cabeçalho', ['cabeçario']], ['entretido', ['entertido']], ['consciência', ['conciência']],
	['discussão', ['discução']], ['paralisar', ['paralizar']], ['através', ['atravéz']], ['ascensão', ['ascenção']],
	['espontâneo', ['expontâneo']], ['xícara', ['chícara']], ['análise', ['analize']], ['pesquisa', ['pesquiza']],
	['catequese', ['catequeze']], ['escultura', ['esculptura']], ['cerâmica', ['seramica', 'ceramica']], ['aquarela', ['acuarela']],
	['azulejo', ['azuleijo']], ['vitral', ['vitrau']], ['porcelana', ['porcelâna', 'porselana']], ['bússola', ['búsola']],
	['caçarola', ['cassarola']], ['empresa', ['impresa']], ['farmacêutico', ['farmaceutico']], ['maquinário', ['maquinario']],
].filter(([g]) => V.has(fold(g)));
const publicLines = publicTypos.map(([g, bs]) => [g, bs.filter(b => fold(b) !== fold(g))]).filter(([, bs]) => bs.length);

report.corrections = { catalog_rules: typoLines.length, catalog_forms: typoForms, catalog_item_hits: typoHits, from_taxonomies: taxTypos.size, public_rules: publicLines.length };
{
	const L = [];
	L.push('# Correções e parônimos — brasiliana3');
	L.push(`# Gerado em ${today} a partir dos ${fmt(N)} itens publicados.`);
	L.push('# Formato: o que a pessoa digita => o que a busca procura também. A forma digitada continua valendo.');
	L.push('# Linhas que começam com # são comentários e não têm efeito.');
	L.push('');
	L.push(`# === 1. Erros de digitação encontrados nos registros (${typoLines.length} regras, ${typoForms} grafias erradas) ===`);
	L.push('# Quem busca a palavra certa passa a encontrar também os itens catalogados com erro.');
	L.push('# Ex.: "legenda" encontra também o item em que se escreveu "legnda".');
	L.push('# Critério: a forma errada aparece em até 3 lugares, não está no dicionário (nem no de inglês),');
	L.push('# não é nome próprio e está a uma letra de uma palavra do dicionário 20+ vezes mais frequente.');
	L.push('# Esta lista também serve de roteiro para corrigir os registros no Tainacan.');
	for (const [g, bs] of typoLines) L.push(`${g} => ${bs.join(', ')}`);
	L.push('');
	L.push(`# === 2. Erros comuns de quem busca (${publicLines.length} regras) ===`);
	L.push('# Palavras que existem no acervo e que o público costuma escrever errado.');
	for (const [g, bs] of publicLines) L.push(`${bs.join(', ')} => ${g}`);
	L.push('');
	L.push('# === 3. Parônimos — NENHUM ativado ===');
	L.push('# Palavras parecidas com sentidos diferentes. Estes 19 pares aparecem no acervo, mas nada indica');
	L.push('# que a catalogação os troque. Só ative se a equipe confirmar a troca nos registros.');
	for (const p of ['descrição => discrição', 'comprimento => cumprimento', 'conserto => concerto', 'eminente => iminente', 'emigrante => imigrante', 'mandato => mandado', 'despensa => dispensa', 'acender => ascender', 'cela => sela', 'censo => senso', 'cessão => sessão', 'tacha => taxa', 'estada => estadia', 'tráfego => tráfico', 'pleito => preito', 'eminência => iminência', 'fusível => fuzil', 'intercessão => interseção', 'peão => pião']) L.push('# ' + p);
	fs.writeFileSync(path.join(OUT, '3-correcoes-e-paronimos.txt'), L.join('\n') + '\n');
}

/* =============================== 1. Sinônimos =============================== */
const ptStopSmall = new Set(['de', 'da', 'do', 'das', 'dos', 'e', 'em', 'a', 'o', 'para']);
const present = term => fold(term).split(/[^a-z0-9]+/).filter(w => w && !ptStopSmall.has(w)).every(w => V.has(w));
const termDf = term => Math.min(...fold(term).split(/[^a-z0-9]+/).filter(w => w && !ptStopSmall.has(w)).map(df));

// Siglas definidas no próprio acervo ("Nome por Extenso (SIGLA)").
const ufs = { AC: 'Acre', AL: 'Alagoas', AP: 'Amapá', AM: 'Amazonas', BA: 'Bahia', CE: 'Ceará', DF: 'Distrito Federal', ES: 'Espírito Santo', GO: 'Goiás', MA: 'Maranhão', MT: 'Mato Grosso', MS: 'Mato Grosso do Sul', MG: 'Minas Gerais', PA: 'Pará', PB: 'Paraíba', PR: 'Paraná', PE: 'Pernambuco', PI: 'Piauí', RJ: 'Rio de Janeiro', RN: 'Rio Grande do Norte', RS: 'Rio Grande do Sul', RO: 'Rondônia', RR: 'Roraima', SC: 'Santa Catarina', SP: 'São Paulo', SE: 'Sergipe', TO: 'Tocantins' };
const ambiguousUf = { AC: 'a.C. (antes de Cristo)', AL: 'palavra "al"', AM: 'palavra inglesa "am"', AP: 'abreviação comum', ES: 'palavra "es" e espanhol', GO: 'palavra inglesa "go"', MA: 'palavra "má"', MS: 'ms = manuscrito', PA: 'palavra "pá"', PE: 'palavra "pé"', SE: 'palavra "se"', TO: 'palavra inglesa "to"', CE: 'palavra "ce" em francês' };
const acr = new Map();
for (const [key, count] of Object.entries(data.acronyms)) {
	const [sig, exp] = key.split('|');
	if (ufs[sig] || exp.length > 80) continue;
	const e = exp.toLowerCase().replace(/\s+/g, ' ').replace('museu da imagem e do sol', 'museu da imagem e do som');
	if (!acr.has(sig) || acr.get(sig)[1] < count) acr.set(sig, [e, count]);
}
// Sigla que também é palavra ("ti", "sema"…) puxaria a expansão em buscas comuns: vai para revisão.
const sigIsWord = s => pt.has(fold(s)) || en.has(fold(s));
const acrLines = [...acr.entries()].filter(([s, [, c]]) => c >= 2 && !sigIsWord(s)).sort((a, b) => b[1][1] - a[1][1]);
const acrOnce = [...acr.entries()].filter(([s, [, c]]) => c < 2 || sigIsWord(s));

const thesaurus = {
	'Técnicas e materiais': [
		['xilogravura', 'gravura em madeira', 'xilografia'], ['litografia', 'litogravura'], ['serigrafia', 'silkscreen', 'silk screen'],
		['aquarela', 'aguarela'], ['guache', 'gouache'], ['nanquim', 'tinta da china'], ['calcogravura', 'gravura em metal'],
		['terracota', 'terracotta', 'barro cozido'], ['madrepérola', 'nácar'], ['latão', 'metal amarelo'], ['papel machê', 'papier mâché'],
		['louça', 'loiça'], ['gelatina e prata', 'prata e gelatina'], ['albumina', 'papel albuminado'], ['cianotipia', 'cianótipo'],
		['fotografia', 'foto'], ['negativo de vidro', 'chapa de vidro'], ['diapositivo', 'slide'], ['cartão de visita', 'carte de visite'],
		['bico de pena', 'desenho a pena'], ['off-set', 'offset', 'ofsete'],
	],
	'Objetos': [
		['quadro', 'pintura', 'tela'], ['gravura', 'estampa'], ['cédula', 'papel moeda'], ['selo postal', 'estampilha'],
		['cartão postal', 'bilhete postal'], ['relógio de bolso', 'relógio de algibeira'], ['xícara', 'chávena'], ['jarra', 'jarro'],
		['farda', 'uniforme militar'], ['carruagem', 'coche'], ['liteira', 'cadeirinha de arruar'], ['mapa', 'carta geográfica'],
		['cartaz', 'pôster'], ['tapete', 'alcatifa'], ['moringa', 'bilha', 'quartinha'], ['candeeiro', 'lampião'],
		['penico', 'urinol', 'bacio'], ['arca', 'baú'], ['canapé', 'sofá'], ['toucador', 'penteadeira'], ['guarda-roupa', 'roupeiro'],
		['vitrola', 'gramofone'], ['máquina fotográfica', 'câmera fotográfica', 'câmara fotográfica'], ['televisor', 'televisão'],
		['automóvel', 'carro'], ['avião', 'aeroplano'], ['botica', 'farmácia'], ['condecoração', 'comenda'],
		['gazeta', 'jornal'], ['sombrinha', 'guarda-chuva'],
	],
	'História e terminologia': [
		['escravizado', 'escravo', 'cativo'], ['indígena', 'índio'], ['Segunda Guerra Mundial', 'II Guerra Mundial'],
		['Primeira Guerra Mundial', 'Grande Guerra'], ['Guerra do Paraguai', 'Guerra da Tríplice Aliança'],
		['Revolução Farroupilha', 'Guerra dos Farrapos'], ['Inconfidência Mineira', 'Conjuração Mineira'],
	],
};
// Remove grupos que só repetem o que o analisador já faz ou que são arriscados.
const thesLines = {}; let thesCount = 0;
for (const [sec, gs] of Object.entries(thesaurus)) {
	thesLines[sec] = [];
	for (const g of gs) {
		const pres = g.filter(present);
		if (!pres.length) continue;
		thesLines[sec].push([g.join(', '), pres.length, Math.max(...pres.map(termDf))]);
		thesCount++;
	}
}
const ufActive = Object.entries(ufs).filter(([s]) => !ambiguousUf[s]);
report.synonyms = { acronyms: acrLines.length, acronyms_once: acrOnce.length, uf_active: ufActive.length, uf_review: Object.keys(ambiguousUf).length, thesaurus: thesCount };
{
	const L = [];
	L.push('# Sinônimos — brasiliana3');
	L.push(`# Gerado em ${today} a partir dos ${fmt(N)} itens publicados. Uma equivalência por linha.`);
	L.push('# Quem buscar qualquer um dos termos encontra os itens descritos com os outros.');
	L.push('# Linhas que começam com # são comentários e não têm efeito: para ativar uma, apague o #.');
	L.push('');
	L.push(`# === 1. Siglas definidas nos próprios registros (${acrLines.length} regras) ===`);
	L.push('# Extraídas de trechos como "Companhia Estadual de Energia Elétrica (CEEE)", vistos 2 ou mais vezes.');
	for (const [s, [e]] of acrLines) L.push(`${s.toLowerCase()}, ${e}`);
	L.push('');
	L.push(`# Vistas uma única vez, ou sigla que também é palavra comum (${acrOnce.length}) — conferir antes de ativar:`);
	for (const [s, [e]] of acrOnce.sort()) L.push(`# ${s.toLowerCase()}, ${e}`);
	L.push('');
	L.push(`# === 2. Unidades da Federação (${ufActive.length} regras) ===`);
	for (const [s, n] of ufActive) L.push(`${s.toLowerCase()}, ${n.toLowerCase()}`);
	L.push('# Siglas de UF que também são palavras comuns — ativar faria a palavra puxar o estado:');
	for (const [s, why] of Object.entries(ambiguousUf)) L.push(`# ${s.toLowerCase()}, ${ufs[s].toLowerCase()}        (${why})`);
	for (const [sec, ls] of Object.entries(thesLines)) {
		L.push('');
		L.push(`# === ${sec} (${ls.length} regras) ===`);
		L.push('# Termos equivalentes do vocabulário museológico; ao menos um deles aparece no acervo.');
		for (const [l] of ls) L.push(l);
	}
	fs.writeFileSync(path.join(OUT, '1-sinonimos.txt'), L.join('\n') + '\n');
}

/* ------------------------------- resumo para revisão ------------------------------- */
console.log(JSON.stringify(report, null, 1));
const pick = (arr, n) => arr.filter((_, i) => i % Math.max(1, Math.floor(arr.length / n)) === 0).slice(0, n);
console.log('\nVARIANTES palavras (amostra espalhada):\n' + pick(words, 40).map(w => w[0]).join('  |  '));
console.log('\nVARIANTES nomes (amostra):\n' + pick(names, 30).map(w => w[0]).join('  |  '));
console.log('\nCORRECOES (amostra):\n' + pick(typoLines, 50).map(([g, b]) => g + ' => ' + b.join(',')).join('  |  '));
console.log('\nSIGLAS:\n' + acrLines.map(([s, [e, c]]) => s + '=' + e + '(' + c + ')').join('  |  '));
console.log('\nTESAURO:\n' + Object.values(thesLines).flat().map(l => l[0] + ' [' + l[1] + ']').join('  |  '));
