-- Шаблон таблицы для smsd
create table sms_campaign_test (
    s_id         serial,
    s_type       text NOT NULL,
    s_name       text NOT NULL,
    s_def        text NOT NULL
);

-- Лог операций smsd
create table sms_log (
    s_id        serial,
    s_dt        TIMESTAMP WITHOUT TIME ZONE DEFAULT now(),
    s_campaign  text NOT NULL,
    s_number    text NOT NULL,
    s_def       text NOT NULL
);

-- Лог операций calld
create table call_log (
    s_id        serial,
    s_dt        TIMESTAMP WITHOUT TIME ZONE DEFAULT now(),
    s_campaign  text NOT NULL,
    s_number    text NOT NULL,
    s_def       text NOT NULL
);

-- Записи кампаний, статус которых отслеживает _watchdog
create table a2i_campaigns (
    s_campaign  text NOT NULL
);
