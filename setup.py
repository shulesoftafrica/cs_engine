from setuptools import setup, find_packages

setup(
    name="cs_engine",
    version="0.1.0",
    description="Customer Success Engine – track customer health and reduce churn.",
    author="ShuleSoft Africa",
    packages=find_packages(exclude=["tests*"]),
    python_requires=">=3.8",
    entry_points={
        "console_scripts": [
            "cs-engine=cs_engine.cli:main",
        ],
    },
    classifiers=[
        "Programming Language :: Python :: 3",
        "License :: OSI Approved :: MIT License",
        "Operating System :: OS Independent",
    ],
)
