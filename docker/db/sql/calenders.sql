CREATE TABLE calenders
(
    id INT AUTO_INCREMENT NOT NULL COMMENT 'ID',
    target_name VARCHAR(256) NOT NULL COMMENT '名称',
    target_id INT NOT NULL COMMENT 'グループ or ユーザー ID',
    target_type ENUM('group', 'user') NOT NULL COMMENT 'グループ or ユーザー',
    target_date DATE NOT NULL COMMENT '手動設定対象日',
    is_holiday TINYINT(4) NOT NULL DEFAULT '0' COMMENT '祝日設定（祝日にする・祝日にしない）',
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
    updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新日時',
    deleted DATETIME COMMENT '削除日時',
    PRIMARY KEY (id)
) COMMENT '手動設定カレンダー情報';