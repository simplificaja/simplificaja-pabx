# App SimplificaJá — API do PABX

App do FusionPBX. Instala por cópia em `/var/www/fusionpbx/app/simplificaja_api/`,
sem tocar em nada do upstream.

## Por que app e não fork

O FusionPBX recebe correção de segurança com frequência (a versão em produção
está num commit de correção de redirecionamento no reset de senha). Forkar a
árvore transformaria cada atualização dessas em merge. Como app, atualizar o
upstream é `git pull` e o nosso código continua no lugar.

## Por que PHP dentro do FusionPBX

Não é preferência de linguagem. É que o FreeSWITCH lê a coluna `dialplan_xml`,
preenchida pelas classes do FusionPBX ao salvar — escrever no Postgres por fora
produz configuração que existe no banco, aparece na tela e é invisível para o
FreeSWITCH. Ver `../../docs/armadilhas.md`, item 1.

## O que vai expor

Endpoints para o painel do SimplificaJá: domínio, ramal, grupo, fila, destino, e
as leituras que a tela de verificação consome. A construir.
