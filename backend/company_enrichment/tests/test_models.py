from app.models import CompanyTarget


def test_company_target_coerces_source_row_id_to_string() -> None:
    company = CompanyTarget.model_validate(
        {
            "siren": "123456789",
            "source_row_id": 42,
        }
    )

    assert company.source_row_id == "42"
