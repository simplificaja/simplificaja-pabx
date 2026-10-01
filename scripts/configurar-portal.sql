-- Substitui o menu do grupo user por telas simples de leitura.
-- Seguro para repetir: só altera associações do grupo user e os três itens
-- com UUIDs reservados para o SimplificaJá Portal.
begin;

delete from v_menu_item_groups
where group_uuid = 'e5ab28b7-1aa7-4e65-ad5e-6adbd9222e76'
  and menu_item_uuid in (
    select menu_item_uuid
    from v_menu_items
    where menu_uuid = 'b4750c3f-2a86-b00d-b7d0-345c14eca286'
      and menu_item_title in (
        'Dashboard', 'Call Detail Records', 'Follow Me', 'Call Forward',
        'Voicemail', 'Conference Rooms', 'Call Block', 'Feature Codes',
        'Account Profile', 'Logout'
      )
  );

delete from v_menu_item_groups
where menu_item_uuid in (
  'd24e33c8-0b88-4aaf-9c3c-5d996b641011',
  'd24e33c8-0b88-4aaf-9c3c-5d996b641012',
  'd24e33c8-0b88-4aaf-9c3c-5d996b641013'
);
delete from v_menu_languages
where menu_item_uuid in (
  'd24e33c8-0b88-4aaf-9c3c-5d996b641011',
  'd24e33c8-0b88-4aaf-9c3c-5d996b641012',
  'd24e33c8-0b88-4aaf-9c3c-5d996b641013'
);
delete from v_menu_items
where menu_item_uuid in (
  'd24e33c8-0b88-4aaf-9c3c-5d996b641011',
  'd24e33c8-0b88-4aaf-9c3c-5d996b641012',
  'd24e33c8-0b88-4aaf-9c3c-5d996b641013'
);

insert into v_menu_items (
  menu_item_uuid, menu_uuid, menu_item_parent_uuid, uuid, menu_item_title,
  menu_item_link, menu_item_icon, menu_item_category, menu_item_order, insert_date
) values
  ('d24e33c8-0b88-4aaf-9c3c-5d996b641011', 'b4750c3f-2a86-b00d-b7d0-345c14eca286', 'b837c17d-e326-4c37-9205-417937a588a5', 'd24e33c8-0b88-4aaf-9c3c-5d996b641001', 'Visão geral', '/app/simplificaja_portal/index.php?view=dashboard', 'fa-solid fa-gauge-high', 'internal', 1, now()),
  ('d24e33c8-0b88-4aaf-9c3c-5d996b641012', 'b4750c3f-2a86-b00d-b7d0-345c14eca286', '2fbe35e3-c82e-411f-b357-48e4c17d3add', 'd24e33c8-0b88-4aaf-9c3c-5d996b641002', 'Ligações', '/app/simplificaja_portal/index.php?view=calls', 'fa-solid fa-phone-volume', 'internal', 1, now()),
  ('d24e33c8-0b88-4aaf-9c3c-5d996b641013', 'b4750c3f-2a86-b00d-b7d0-345c14eca286', '2fbe35e3-c82e-411f-b357-48e4c17d3add', 'd24e33c8-0b88-4aaf-9c3c-5d996b641003', 'Ramais', '/app/simplificaja_portal/index.php?view=extensions', 'fa-solid fa-headset', 'internal', 2, now());

insert into v_menu_item_groups (menu_item_group_uuid, menu_uuid, menu_item_uuid, group_name, group_uuid, insert_date)
values
  ('d24e33c8-0b88-4aaf-9c3c-5d996b641021', 'b4750c3f-2a86-b00d-b7d0-345c14eca286', 'd24e33c8-0b88-4aaf-9c3c-5d996b641011', 'user', 'e5ab28b7-1aa7-4e65-ad5e-6adbd9222e76', now()),
  ('d24e33c8-0b88-4aaf-9c3c-5d996b641022', 'b4750c3f-2a86-b00d-b7d0-345c14eca286', 'd24e33c8-0b88-4aaf-9c3c-5d996b641012', 'user', 'e5ab28b7-1aa7-4e65-ad5e-6adbd9222e76', now()),
  ('d24e33c8-0b88-4aaf-9c3c-5d996b641023', 'b4750c3f-2a86-b00d-b7d0-345c14eca286', 'd24e33c8-0b88-4aaf-9c3c-5d996b641013', 'user', 'e5ab28b7-1aa7-4e65-ad5e-6adbd9222e76', now());

insert into v_menu_languages (menu_language_uuid, menu_uuid, menu_item_uuid, menu_language, menu_item_title, insert_date)
values
  ('d24e33c8-0b88-4aaf-9c3c-5d996b641031', 'b4750c3f-2a86-b00d-b7d0-345c14eca286', 'd24e33c8-0b88-4aaf-9c3c-5d996b641011', 'en-us', 'Overview', now()),
  ('d24e33c8-0b88-4aaf-9c3c-5d996b641032', 'b4750c3f-2a86-b00d-b7d0-345c14eca286', 'd24e33c8-0b88-4aaf-9c3c-5d996b641012', 'en-us', 'Calls', now()),
  ('d24e33c8-0b88-4aaf-9c3c-5d996b641033', 'b4750c3f-2a86-b00d-b7d0-345c14eca286', 'd24e33c8-0b88-4aaf-9c3c-5d996b641013', 'en-us', 'Extensions', now()),
  ('d24e33c8-0b88-4aaf-9c3c-5d996b641034', 'b4750c3f-2a86-b00d-b7d0-345c14eca286', 'd24e33c8-0b88-4aaf-9c3c-5d996b641011', 'pt-br', 'Visão geral', now()),
  ('d24e33c8-0b88-4aaf-9c3c-5d996b641035', 'b4750c3f-2a86-b00d-b7d0-345c14eca286', 'd24e33c8-0b88-4aaf-9c3c-5d996b641012', 'pt-br', 'Ligações', now()),
  ('d24e33c8-0b88-4aaf-9c3c-5d996b641036', 'b4750c3f-2a86-b00d-b7d0-345c14eca286', 'd24e33c8-0b88-4aaf-9c3c-5d996b641013', 'pt-br', 'Ramais', now());

-- Após o login, usuários do grupo user entram na nova visão geral. É uma
-- preferência por usuário; administradores continuam no destino padrão.
delete from v_user_settings
where user_setting_description = 'SimplificaJá: abrir visão geral do PABX após login';

insert into v_user_settings (
  user_setting_uuid, user_uuid, domain_uuid, user_setting_category,
  user_setting_subcategory, user_setting_name, user_setting_value,
  user_setting_order, user_setting_enabled, user_setting_description, insert_date
)
select gen_random_uuid(), ug.user_uuid, ug.domain_uuid, 'login', 'destination',
       'text', '/app/simplificaja_portal/index.php?view=dashboard', 1, true,
       'SimplificaJá: abrir visão geral do PABX após login', now()
from v_user_groups ug
where ug.group_name = 'user'
  and exists (
    select 1 from v_group_permissions gp
    where gp.group_uuid = ug.group_uuid
      and gp.permission_name = 'xml_cdr_view'
      and gp.permission_assigned = 'true'
  );

commit;
