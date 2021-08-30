CREATE TABLE summarize_commands
(
    id INT AUTO_INCREMENT NOT NULL COMMENT 'ID',
    target_name VARCHAR(256) NOT NULL COMMENT '名称',
    target_id INT NOT NULL COMMENT 'グループ or ユーザー ID',
    target_type ENUM('group', 'user') NOT NULL COMMENT 'グループ or ユーザー',
    commands VARCHAR(4096) NOT NULL COMMENT 'コマンド ID の配列（json 形式）',
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
    updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新日時',
    deleted DATETIME COMMENT '削除日時',
    PRIMARY KEY (id)
) COMMENT 'コマンド情報';