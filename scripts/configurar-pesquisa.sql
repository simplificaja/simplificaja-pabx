-- Pesquisa de avaliação: a nota que o cliente digita no fim da ligação.
--
-- O prefixo `v_` e a coluna `domain_uuid` não são estética. A varredura de
-- remoção de cliente em api_dominio.php casa exatamente `v\_%` com uma coluna
-- `domain_uuid`, e é por isso que apagar um cliente limpa as notas dele sem
-- nenhum código novo. Renomear sem manter os dois deixa as notas órfãs no
-- banco -- o mesmo defeito que levou aquela varredura de 8 para 85 tabelas.
--
-- Idempotente, e serve tanto para instalação nova quanto para atualizar uma
-- que ficou na forma antiga (quando `call_uuid` era único sozinho).

create table if not exists v_simplificaja_pesquisas (
    pesquisa_uuid  uuid        primary key,
    domain_uuid    uuid        not null,
    call_uuid      uuid        not null,
    pesquisa       text        not null,
    nota           smallint    not null check (nota between 1 and 5),
    ramal          text,
    extension_uuid uuid,
    fila           text,
    telefone       text,
    criado_em      timestamptz not null default now(),
    unique (call_uuid, pesquisa)
);

-- Daqui para baixo é a atualização da forma antiga. Tudo condicional, então
-- numa instalação nova as linhas abaixo não têm efeito.

-- A coluna que diz QUAL pergunta a nota responde. Sem ela, duas perguntas em
-- sequência na mesma ligação ficam indistinguíveis.
alter table v_simplificaja_pesquisas add column if not exists pesquisa text;
update v_simplificaja_pesquisas set pesquisa = 'desconhecida' where pesquisa is null;
alter table v_simplificaja_pesquisas alter column pesquisa set not null;

-- Uma ligação pode gerar mais de uma nota: a fila pode pedir "nota do
-- atendente" e depois "nota da empresa". Com `call_uuid` único sozinho, a
-- segunda nota era descartada em silêncio pelo `on conflict do nothing`.
alter table v_simplificaja_pesquisas
    drop constraint if exists v_simplificaja_pesquisas_call_uuid_key;

do $$
begin
    if not exists (
        select 1 from pg_constraint
         where conrelid = 'v_simplificaja_pesquisas'::regclass
           and contype = 'u'
           and pg_get_constraintdef(oid) = 'UNIQUE (call_uuid, pesquisa)'
    ) then
        alter table v_simplificaja_pesquisas
            add constraint v_simplificaja_pesquisas_call_uuid_pesquisa_key
            unique (call_uuid, pesquisa);
    end if;
end $$;

create index if not exists idx_simplificaja_pesquisas_dominio
    on v_simplificaja_pesquisas (domain_uuid, criado_em desc);

-- O script roda como `postgres`, então a tabela nasceria dele. Quem escreve de
-- verdade é o `fusionpbx`: é com esse papel que a API do PHP conecta e também o
-- Lua do plano de discagem (conferido com `select current_user` dentro do
-- Database.new('system')). Dono em vez de `grant` para ficar igual a toda
-- tabela `v_*` que o FusionPBX já tem.
alter table v_simplificaja_pesquisas owner to fusionpbx;

-- Verificação: 10 colunas, a unicidade pela dupla, e o dono certo.
select count(*) as colunas from information_schema.columns
 where table_name = 'v_simplificaja_pesquisas';
select pg_get_constraintdef(oid) as unicidade from pg_constraint
 where conrelid = 'v_simplificaja_pesquisas'::regclass and contype = 'u';
select tableowner as dono from pg_tables
 where tablename = 'v_simplificaja_pesquisas';
