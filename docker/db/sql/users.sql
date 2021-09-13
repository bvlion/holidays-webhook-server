CREATE TABLE users
(
    id INT AUTO_INCREMENT NOT NULL COMMENT 'ID',
    groups_id INT NOT NULL COMMENT 'グループの ID',
    api_token CHAR(60) UNIQUE COMMENT '認証トークン',
    user_name VARCHAR(256) NOT NULL COMMENT 'ユーザー名（初期ユーザーは Google に登録している名称）',
    login_id VARCHAR(256) UNIQUE COMMENT 'ログイン ID',
    login_password CHAR(41) COMMENT 'ログインパスワード（ユーザーで任意）',
    country_code CHAR(2) NOT NULL DEFAULT 'jp' COMMENT '国コード',
    fcm_token VARCHAR(256) COMMENT '配信トークン',
    owner_flag TINYINT(4) NOT NULL DEFAULT '0' COMMENT 'グループオーナーフラグ',
    created_at DATETIME NOT NULL COMMENT '作成日時',
    updated_at DATETIME NOT NULL COMMENT '更新日時',
    deleted_at DATETIME COMMENT '削除日時',
    PRIMARY KEY (id)
) COMMENT 'ユーザー情報';
