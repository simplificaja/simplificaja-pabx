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

\echo '== 3. RTCP no perfil externo: ver a perda que a operadora reporta =='
-- Sem RTCP no perfil que fala com a operadora, nos ficamos CEGOS na direcao que
-- importa: da para medir o audio que CHEGA (rtp_audio_in_mos deu 4,50), e nao o
-- que a operadora recebe de nos. Quando o Isaac relatou picote, a medicao
-- provou que a nossa saida e limpa -- 10.631 pacotes, 3 buracos acima de 40ms --
-- mas nao havia como ver quanto se perdia no caminho.
--
-- Com RTCP ligado, a operadora manda relatorio de recepcao e os contadores
-- rtp_audio_out_* passam a ter numero. Mesmo intervalo que o perfil interno ja
-- usa, 5 segundos.
--
-- Risco conhecido: isto acrescenta atributos de RTCP no SDP. Operadora
-- implicante pode recusar -- se a entrada parar depois disto, e o primeiro
-- suspeito, e desfazer e pôr sip_profile_setting_enabled em false.

insert into v_sip_profile_settings
  (sip_profile_setting_uuid, sip_profile_uuid, sip_profile_setting_name,
   sip_profile_setting_value, sip_profile_setting_enabled,
   sip_profile_setting_description)
select gen_random_uuid(), p.sip_profile_uuid, 'rtcp-audio-interval-msec', '5000', true,
       'Relatorio de recepcao da operadora: sem isto nao da para medir a perda de saida'
from v_sip_profiles p
where p.sip_profile_name = 'external'
  and not exists (
    select 1 from v_sip_profile_settings s
    where s.sip_profile_uuid = p.sip_profile_uuid
      and s.sip_profile_setting_name = 'rtcp-audio-interval-msec'
  );

\echo '== 4. Fila sem anuncio nao derruba mais o FreeSWITCH =='
-- O mod_callcenter deste build chama, no instante em que o atendente atende,
-- `switch_ivr_stop_displace_session(sessao, queue->announce)` sem checar nulo
-- -- e essa funcao usa o ponteiro direto como chave de hashtable. Ponteiro
-- nulo ali e SIGSEGV no processo inteiro do FreeSWITCH. (O ramo de abandono
-- nao tem a chamada: desistir de esperar e seguro.)
--
-- Backtrace do core:
--   switch_hash_default(ky=0x0)               switch_hashtable.h:230
--   switch_channel_get_private(key=0x0)       switch_channel.c:1105
--   switch_ivr_stop_displace_session(file=0)  switch_ivr_async.c:993
--   callcenter_function                       mod_callcenter.c:3307
--
-- O systemd tem Restart=always e levanta em dois segundos, entao nada parece
-- caido: o sintoma que aparece e "a ligacao nao tem voz", porque os dois
-- telefones seguem mandando RTP para um processo que nao existe mais.
--
-- A chamada NAO existe no FreeSWITCH oficial, nem na release nem no master. Ela
-- vem do fork do FusionPBX: commit d871c84ab8, "Stop announcement on agent call
-- answer" (08/04/2026), em github.com/fusionpbx/freeswitch. Tres linhas, nenhuma
-- checagem de nulo. Enquanto o build vier desse fork, nenhuma fila pode ter o
-- campo vazio.
--
-- O api_fila.php ja grava o valor, mas a tela nativa do FusionPBX nao -- e o
-- campo la e opcional. Este gatilho cobre TODO caminho de escrita, inclusive o
-- deles e SQL na mao. Nao da para usar DEFAULT de coluna: o formulario do
-- FusionPBX envia o campo como string vazia, e default so dispara quando a
-- coluna e omitida.
--
-- Silencio com frequencia zero nunca toca: o anuncio periodico nao e recurso
-- que oferecemos, o campo existe so para nao ser nulo.

create or replace function simplificaja_fila_exige_anuncio()
returns trigger as $$
begin
  if coalesce(new.queue_announce_sound, '') = '' then
    new.queue_announce_sound := 'silence_stream://1000';
    new.queue_announce_frequency := coalesce(new.queue_announce_frequency, 0);
  end if;
  return new;
end;
$$ language plpgsql;

drop trigger if exists simplificaja_fila_exige_anuncio on v_call_center_queues;
create trigger simplificaja_fila_exige_anuncio
  before insert or update on v_call_center_queues
  for each row execute function simplificaja_fila_exige_anuncio();

-- Conserta as filas que ja existem. O update dispara o gatilho acima.
update v_call_center_queues
set queue_announce_sound = queue_announce_sound
where coalesce(queue_announce_sound, '') = '';

\echo '== 5. Conferencia =='
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

select queue_extension, queue_name,
       coalesce(queue_announce_sound, '(NULO -- o PABX vai cair)') as anuncio,
       coalesce(queue_announce_frequency::text, '(nulo)') as frequencia
from v_call_center_queues
order by queue_extension;
