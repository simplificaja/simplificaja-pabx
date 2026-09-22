-- Marca do SimplificaJá no painel do FusionPBX.
--
-- Roda em instalação nova, e é idempotente: pode rodar de novo sem estragar.
--
-- Sobrevive a `domains::upgrade()`, que roda a cada cliente criado. Conferido no
-- código deles: `upgrade()` não tem uma única operação de arquivo -- só inclui
-- `app_config.php`, `app_menu.php` e `app_defaults.php` -- e o `settings()`
-- compara por `default_setting_uuid`, inserindo só o que falta. Trocar o VALOR
-- de uma linha existente, portanto, fica.
--
-- Os arquivos de imagem vão para /var/www/fusionpbx/themes/simplificaja/images/
-- (ver marca.sh).

-- ── linhas que já existem: trocar o valor E habilitar ──
--
-- `default_setting_enabled` importa tanto quanto o valor: o `settings->get()`
-- ignora linha desabilitada e cai no padrão do FusionPBX. As linhas de marca
-- nascem desabilitadas, então trocar só o valor não muda nada na tela -- e o
-- sintoma é silencioso, porque o SQL responde `UPDATE 1` do mesmo jeito.
update v_default_settings
   set default_setting_value = 'SimplificaJá', default_setting_enabled = true
 where default_setting_category = 'theme' and default_setting_subcategory = 'title';

-- A barra lateral e escura, entao ali vai a variante de palavra branca. Com o
-- `logo.svg` normal a palavra (#1F1B2E) fica quase invisivel sobre o fundo
-- escuro -- o simbolo aparece e o nome some.
update v_default_settings
   set default_setting_value = '/themes/simplificaja/images/logo_dark.svg', default_setting_enabled = true
 where default_setting_category = 'theme' and default_setting_subcategory = 'menu_side_brand_image_expanded';

update v_default_settings
   set default_setting_value = '/themes/simplificaja/images/logo_thumbnail.svg', default_setting_enabled = true
 where default_setting_category = 'theme' and default_setting_subcategory = 'menu_side_brand_image_contracted';

update v_default_settings
   set default_setting_value = '#5c2ee6', default_setting_enabled = true
 where default_setting_category = 'theme'
   and default_setting_subcategory in ('button_background_color', 'button_background_color_bottom',
                                       'text_link_color', 'dashboard_label_background_color');

update v_default_settings
   set default_setting_value = '#4a22bd', default_setting_enabled = true
 where default_setting_category = 'theme'
   and default_setting_subcategory in ('button_background_color_hover',
                                       'button_background_color_bottom_hover',
                                       'text_link_color_hover', 'dashboard_label_background_color_hover');

-- Pintar o fundo sem pintar o texto deixa letra escura sobre roxo. O valor
-- branco ja estava nestas linhas; faltava habilitar. A sombra preta fica
-- desabilitada de proposito -- sobre branco em roxo ela suja em vez de ajudar.
update v_default_settings
   set default_setting_value = '#ffffff', default_setting_enabled = true
 where default_setting_category = 'theme'
   and default_setting_subcategory in ('dashboard_label_text_color',
                                       'dashboard_label_text_color_hover');

-- ── linhas que NÃO existem: inserir ──
--
-- `theme.logo_login` e `theme.logo` não vêm de nenhum `app_defaults.php`: é por
-- isso que o `footer.php` cai no arquivo padrão do FusionPBX. Inserir é seguro
-- justamente por isso -- o `upgrade()` só reinsere UUID que os app_defaults
-- conhecem, então não há duplicata para disputar.
insert into v_default_settings
  (default_setting_uuid, default_setting_category, default_setting_subcategory,
   default_setting_name, default_setting_value, default_setting_enabled,
   default_setting_description)
select gen_random_uuid(), 'theme', t.v, 'text', t.valor, true, 'SimplificaJá'
  from (values ('logo_login', '/themes/simplificaja/images/logo.svg'),
               ('logo',       '/themes/simplificaja/images/logo.svg'),
               ('login_logo_width', '260px'),
               -- Sem esta linha o `footer.php` cai em
               -- `/themes/default/favicon.ico`, que e a marca deles na aba.
               ('favicon',    '/themes/simplificaja/images/favicon.ico')) as t(v, valor)
 where not exists (
   select 1 from v_default_settings
    where default_setting_category = 'theme' and default_setting_subcategory = t.v);

-- ── cor gravada em cada cartao do painel ──
--
-- `core/dashboard/index.php:683` faz
--   $row['widget_label_text_color'] ?? $settings->get('theme', 'dashboard_label_text_color')
-- ou seja: a cor do cartao vence a do tema. Os cartoes nascem com #444444
-- gravado, entao pintar o fundo de roxo pelo tema deixava letra escura sobre
-- roxo -- e mexer no tema nao resolvia, porque o tema nunca era consultado.
--
-- Anular (NULL, nao string vazia: `??` so cai no padrao com NULL) devolve o
-- controle ao tema, e ai trocar a marca no futuro passa a valer aqui tambem.
update v_dashboard_widgets set widget_label_text_color = null
 where widget_label_text_color is not null;
