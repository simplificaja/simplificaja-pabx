-- Pesquisa de avaliação: a nota que o cliente digita no fim da ligação.
--
-- O prefixo `v_` e a coluna `domain_uuid` não são estética. A varredura de
-- remoção de cliente em api_dominio.php casa exatamente `v\_%` com uma coluna
-- `domain_uuid`, e é por isso que apagar um cliente limpa as notas dele sem
-- nenhum código novo. Renomear sem manter os dois deixa as notas órfãs no
-- banco -- o mesmo defeito que levou aquela varredura de 8 para 85 tabelas.
--
-- Idempotente: pode rodar de novo sem efeito.

create table if not exists v_simplificaja_pesquisas (
    pesquisa_uuid  uuid        primary key,
    domain_uuid    uuid        not null,
    call_uuid      uuid        not null unique,
    nota           smallint    not null check (nota between 1 and 5),
    ramal          text,
    extension_uuid uuid,
    fila           text,
    telefone       text,
    criado_em      timestamptz not null default now()
);

create index if not exists idx_simplificaja_pesquisas_dominio
    on v_simplificaja_pesquisas (domain_uuid, criado_em desc);

-- O script roda como `postgres`, então a tabela nasceria dele. Quem escreve de
-- verdade é o `fusionpbx`: é com esse papel que a API do PHP conecta e também o
-- Lua do plano de discagem (conferido com `select current_user` dentro do
-- Database.new('system')). Dono em vez de `grant` para ficar igual a toda
-- tabela `v_*` que o FusionPBX já tem.
alter table v_simplificaja_pesquisas owner to fusionpbx;

-- Verificação: 9 colunas, e o dono certo.
select count(*) as colunas from information_schema.columns
 where table_name = 'v_simplificaja_pesquisas';
select tableowner as dono from pg_tables
 where tablename = 'v_simplificaja_pesquisas';
