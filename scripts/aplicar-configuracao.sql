-- Configurações do FusionPBX que diferem do padrão de instalação.
-- Idempotente: pode rodar de novo sem duplicar nada.
--
--   su - postgres -c 'psql -d fusionpbx -f aplicar-configuracao.sql'
--
-- Depois: rm -rf /var/cache/fusionpbx/* && fs_cli -x reloadxml

\echo '== 1. Perfil externo aceita chamada do tronco sem autenticar =='
-- Com auth-calls = true o PBX responde 407 a toda chamada que a operadora
-- entrega, e nenhuma ligação de entrada completa. A segurança do perfil
-- externo vem da lista de IPs (apply-inbound-acl), não da autenticação.
-- O perfil interno continua com auth-calls = true: ramal tem senha.
update v_sip_profile_settings s
set sip_profile_setting_value = 'false'
from v_sip_profiles p
where s.sip_profile_uuid = p.sip_profile_uuid
  and p.sip_profile_name = 'external'
  and s.sip_profile_setting_name = 'auth-calls'
  and s.sip_profile_setting_value is distinct from 'false';

\echo '== 2. IPs de tronco liberados no Event Guard =='
-- O Event Guard bane quem autentica contra IP puro em vez de domínio, que é a
-- cara de qualquer tronco. Ele descarta no firewall, o FreeSWITCH acusa
-- timeout, e a captura de pacote mostra a resposta chegando -- porque tcpdump
-- captura antes do netfilter. Sem esta liberação o tronco nunca sobe.
--
-- Acrescentar aqui o IP de cada operadora (ou o do MagnusBilling, se ele
-- passar a intermediar -- aí vira um IP só).
insert into v_access_control_nodes
  (access_control_node_uuid, access_control_uuid, node_type, node_cidr, node_description, insert_date)
select gen_random_uuid(), ac.access_control_uuid, 'allow', f.cidr, f.descricao, now()
from v_access_controls ac
cross join (values
  ('177.11.50.217/32', 'Operadora NextBilling - tronco 75681')
) as f(cidr, descricao)
where ac.access_control_name = 'providers'
  and not exists (
    select 1 from v_access_control_nodes n where n.node_cidr = f.cidr
  );

\echo '== 3. Conferencia =='
select p.sip_profile_name, s.sip_profile_setting_name, s.sip_profile_setting_value
from v_sip_profiles p
join v_sip_profile_settings s on s.sip_profile_uuid = p.sip_profile_uuid
where s.sip_profile_setting_name in ('auth-calls', 'apply-inbound-acl')
  and p.sip_profile_name in ('internal', 'external')
order by 1, 2;

select ac.access_control_name, n.node_type, n.node_cidr, n.node_description
from v_access_control_nodes n
join v_access_controls ac on ac.access_control_uuid = n.access_control_uuid
where ac.access_control_name = 'providers';
