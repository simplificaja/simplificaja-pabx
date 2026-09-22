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

-- ── linhas que já existem: trocar o valor ──
update v_default_settings set default_setting_value = 'SimplificaJá'
 where default_setting_category = 'theme' and default_setting_subcategory = 'title';

update v_default_settings set default_setting_value = '/themes/simplificaja/images/logo.svg'
 where default_setting_category = 'theme' and default_setting_subcategory = 'menu_side_brand_image_expanded';

update v_default_settings set default_setting_value = '/themes/simplificaja/images/logo_thumbnail.svg'
 where default_setting_category = 'theme' and default_setting_subcategory = 'menu_side_brand_image_contracted';

update v_default_settings set default_setting_value = '#5c2ee6'
 where default_setting_category = 'theme'
   and default_setting_subcategory in ('button_background_color', 'button_background_color_bottom',
                                       'text_link_color', 'dashboard_label_background_color');

update v_default_settings set default_setting_value = '#4a22bd'
 where default_setting_category = 'theme'
   and default_setting_subcategory in ('button_background_color_hover',
                                       'button_background_color_bottom_hover',
                                       'text_link_color_hover', 'dashboard_label_background_color_hover');

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
               ('login_logo_width', '260px')) as t(v, valor)
 where not exists (
   select 1 from v_default_settings
    where default_setting_category = 'theme' and default_setting_subcategory = t.v);
