CREATE TABLE users
(
    id INT AUTO_INCREMENT NOT NULL COMMENT 'ID',
    groups_id INT NOT NULL COMMENT 'グループの ID',
    user_token VARCHAR(256) NOT NULL COMMENT '認証トークン（自動生成）',
    user_name VARCHAR(256) NOT NULL COMMENT 'ユーザー名（初期ユーザーは Google に登録している名称）',
    users_password CHAR(41) COMMENT 'ログインパスワード（ユーザーで任意）',
    fcm_token VARCHAR(256) COMMENT '配信トークン',
    owner_flag TINYINT(4) NOT NULL DEFAULT '0' COMMENT 'グループオーナーフラグ',
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
    updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新日時',
    deleted DATETIME COMMENT '削除日時',
    PRIMARY KEY (id)
) COMMENT 'ユーザー情報';