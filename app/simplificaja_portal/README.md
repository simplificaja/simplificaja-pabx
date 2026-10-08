# SimplificaJá Portal

Aplicação somente de leitura para o perfil `user` do FusionPBX. Fornece visão
geral, histórico simples de ligações e mapa de disponibilidade dos ramais.

Os dados são sempre limitados ao `domain_uuid` da sessão. Chamadas históricas
seguem o escopo nativo: o perfil `user` enxerga apenas os ramais associados ao
seu usuário; papéis com `xml_cdr_domain` podem ver todo o domínio. O mapa de
ramais segue o escopo equivalente de domínio (`registration_domain` ou
`call_active_domain`), sem exibir endereço IP ou ações de provisionamento.

O app usa as classes já instaladas pelo FusionPBX para autenticação, banco de
dados, Event Socket e leitura de registros SIP. Não altera o FusionPBX upstream
nem executa ações de controle sobre chamadas ou ramais.

## Instalação

Copiar o diretório para `/var/www/fusionpbx/app/simplificaja_portal/` e executar
`scripts/configurar-portal.sql` no banco. O script adiciona os três itens de
menu para o grupo `user` e remove desse grupo os atalhos antigos de dashboard,
CDR detalhado, desvios, caixa postal, salas, bloqueios, códigos, perfil e saída.
Os menus e permissões dos outros grupos não são alterados.

## Os dois papéis

| Grupo | Vê | Para quem |
|---|---|---|
| `user` | só os ramais associados ao próprio usuário | atendente |
| `gestor` | o domínio inteiro | dono da empresa |

O grupo `user` tem `xml_cdr_view` mas não `xml_cdr_domain`, então o portal
filtra tudo pelos ramais do usuário. Para o dono, que precisa ver a nota de
**todos** os atendentes, isso mostrava nada.

Não dá para resolver soltando `xml_cdr_domain` no grupo `user`: faria todo
atendente ver as ligações e as notas dos colegas.

`gestor` é o `user` clonado mais `xml_cdr_domain`, `registration_domain` e
`call_active_domain` -- exatamente as três que o portal consulta. Clonado em vez
de escolhido a dedo porque entre as 104 permissões do `user` estão as de login e
perfil.

Conferido em 08/10/2026 com duas sessões reais e uma nota de um ramal não
vinculado: o `user` via 2 respostas, o `gestor` via 3.

## Ao criar um usuário novo

O destino de login é gravado **por usuário** em `v_user_settings`, e o
`configurar-portal.sql` insere para quem estava no grupo `user` **no momento em
que ele rodou**. Usuário criado depois cai na tela padrão do FusionPBX em vez da
visão geral -- aconteceu em 08/10/2026.

Não há configuração por grupo nesta versão (só existem `v_default_settings`,
global, e `v_domain_settings`, por domínio), então a regra é:

```
criar o usuário  →  rodar configurar-portal.sql de novo
```

O script é idempotente: apaga pela descrição e reinsere para todos os usuários
do grupo `user` que existirem naquele instante.

As alterações de menu são idempotentes. As páginas exigem autenticação e a
permissão existente `xml_cdr_view`, concedida ao grupo `user` nesta instalação.
O script também define a visão geral como destino de login para os usuários
desse grupo, sem mudar a tela inicial dos administradores.
